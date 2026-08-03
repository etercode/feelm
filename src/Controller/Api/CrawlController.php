<?php

namespace App\Controller\Api;

use App\Presenter\WorkPresenter;
use App\Repository\WorkRepository;
use App\Service\Catalog\WorkHydrator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Read-only view of how the catalog crawl is getting on.
 *
 * The crawl runs detached on the server for something like thirteen hours, so
 * the only way to watch it was to hold an SSH session open. This is the same
 * information over HTTP, which a phone can read.
 *
 * Movies and series are crawled from separate queues by separate commands, so
 * everything here takes a `?type=`. It defaults to movies, which is what the
 * page asked for before there was anything else to ask for.
 */
final class CrawlController extends AbstractController
{
    /** How far back to look when working out the current rate. */
    private const RATE_WINDOW_MINUTES = 10;

    /** No recent arrivals for this long means nothing is running. */
    private const IDLE_MINUTES = 3;

    /**
     * How long a status answer is served from cache.
     *
     * Counting the catalog twice — once for the type, once for the popular tier
     * — is two sequential scans of a 1.5 GB table, and the page polls every ten
     * seconds from every tab anybody leaves open. Half a poll interval means at
     * most one pass through the table per five seconds however many people are
     * watching, and nothing on this page is worth being fresher than that.
     */
    private const CACHE_SECONDS = 5;

    /** Which queue table backs which type. */
    private const QUEUES = [
        'movie' => 'tmdb_movie_ids',
        'series' => 'tmdb_series_ids',
    ];

    /**
     * The popularity above which a title is worth crawling at all.
     *
     * The export holds every id TMDB knows, and about a million of them are
     * shorts, industrial films and festival entries nobody will ever search
     * for. The crawl is run with `--min-popularity=1` and stops when this tier
     * is exhausted, so this is the denominator that describes the actual job —
     * progress against the whole export climbs to about two thirds and then
     * stops, which looks like a stall and is not one.
     *
     * Same threshold as the partial full-text index, so search and the crawler
     * page agree on which titles count.
     */
    private const NOTABLE_POPULARITY = 1.0;

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkRepository $works,
        private readonly WorkPresenter $presenter,
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route('/api/crawl/status', name: 'api_crawl_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $type = $this->type($request);

        return $this->json($this->cache->get(
            'crawl.status.'.$type,
            function (ItemInterface $item) use ($type): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->measure($type);
            },
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(string $type): array
    {
        $queue = self::QUEUES[$type];

        $counts = $this->connection->executeQuery(
            "SELECT
                COUNT(*)                                        AS total,
                COUNT(*) FILTER (WHERE crawled_at IS NOT NULL)  AS crawled,
                COUNT(*) FILTER (WHERE crawled_at IS NULL)      AS remaining,
                COUNT(*) FILTER (WHERE popularity >= :notable)  AS notable_total,
                MAX(crawled_at)                                 AS last_crawled_at
             FROM {$queue}",
            ['notable' => self::NOTABLE_POPULARITY],
        )->fetchAssociative() ?: [];

        $total = (int) ($counts['total'] ?? 0);
        $inCatalog = $this->works->countByType($type);

        /*
         * `crawled_at` is stamped when a whole run finishes, and the runs are
         * batches of two thousand against a backlog of a million — it reads
         * 15,324 where the catalog holds 742,000. The catalog is the record of
         * what was actually done, so it wins; the column is still reported as
         * itself for anything that wants it, and clamped to the queue because
         * the catalog can hold titles today's export no longer lists.
         */
        $crawled = min(max((int) ($counts['crawled'] ?? 0), $inCatalog), $total);

        // Rate from what actually landed, rather than from anything the command
        // reports — this stays true whether or not a crawl is running.
        $recent = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM works
             WHERE deleted_at IS NULL AND type = :type
               AND added_at > NOW() - make_interval(mins => :window)",
            ['type' => $type, 'window' => self::RATE_WINDOW_MINUTES],
        )->fetchOne();

        $perMinute = $recent / self::RATE_WINDOW_MINUTES;
        $lastAdded = $this->connection->executeQuery(
            'SELECT MAX(added_at) FROM works WHERE deleted_at IS NULL AND type = :type',
            ['type' => $type],
        )->fetchOne();

        $payload = [
            'type' => $type,
            'total' => $total,
            'crawled' => $crawled,
            'remaining' => max($total - $crawled, 0),
            'percent' => $total > 0 ? round($crawled / $total * 100, 2) : 0.0,
            'inCatalog' => $inCatalog,
            'perMinute' => round($perMinute, 1),
            'perSecond' => round($perMinute / 60, 2),
            // Null rather than infinity when nothing is moving: the frontend
            // shows "—" instead of a number that would be a guess.
            'etaHours' => $perMinute > 0 ? round(max($total - $crawled, 0) / $perMinute / 60, 1) : null,
            'running' => $this->isRunning($lastAdded),
            'lastAddedAt' => $this->atom($lastAdded),
            'lastCrawledAt' => $this->atom($counts['last_crawled_at'] ?? null),
            'notable' => $this->notable($type, (int) ($counts['notable_total'] ?? 0), $perMinute),
        ];

        /*
         * What the crawl threw away and why.
         *
         * Only series have this: anyone can add a show to TMDB and a good part
         * of the export is a stub with no poster or no episodes, so the crawler
         * filters as it goes. Publishing the tally is what keeps that filter
         * honest — a rule quietly eating a tenth of the catalog shows up here
         * rather than as titles nobody can find.
         */
        if ('series' === $type) {
            $payload['filtered'] = $this->filtered($queue);
            $payload['filteredTotal'] = array_sum($payload['filtered']);
        }

        return $payload;
    }

    /**
     * The most recently crawled titles, newest first.
     *
     * Paged rather than capped: the queue is three quarters of a million rows
     * and the whole point is to be able to look through what arrived.
     */
    #[Route('/api/crawl/recent', name: 'api_crawl_recent', methods: ['GET'])]
    public function recent(Request $request, WorkHydrator $hydrator): JsonResponse
    {
        $type = $this->type($request);
        $limit = max(1, min(60, (int) $request->query->get('limit', 24)));
        $page = max(1, (int) $request->query->get('page', 1));

        $total = $this->works->countByType($type);
        $pages = (int) ceil($total / $limit);

        $works = $this->works->createQueryBuilder('w')
            ->where('w.type = :type')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('type', $type)
            // id breaks ties: added_at has second resolution and a crawl stores
            // far more than one title a second, so ordering on it alone would
            // let rows swap places between pages.
            ->orderBy('w.addedAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /*
         * Without this the presenter walks each work's relations and Doctrine
         * loads them one work at a time: 24 cards was 74 queries — a ratings
         * lookup and a genre lookup and an external-id lookup apiece — for a
         * page that draws a poster and a title. This endpoint does not go
         * through WorkSearch, which preloads on its own way out.
         */
        $hydrator->preload($works, [WorkHydrator::RATINGS]);

        return $this->json([
            'type' => $type,
            // listItem, not one(): this is a wall of posters, and one() would
            // fetch every credit — and for a series every episode — per card.
            'items' => array_map(fn ($work) => $this->presenter->listItem($work), $works),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
        ]);
    }

    /**
     * Progress against the tier the crawl is actually working through.
     *
     * How many are held comes from `works` rather than the queue's `crawled_at`,
     * which is stamped only when a whole run finishes: against a backlog of a
     * million in batches of two thousand it reads 13,324 where the truth is
     * 164,612. The queue is the list of what to do; the catalog is the record of
     * what was done, and only one of them can be believed.
     *
     * @return array<string, int|float|null>
     */
    private function notable(string $type, int $total, float $perMinute): array
    {
        $held = (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM works
             WHERE type = :type AND deleted_at IS NULL AND popularity >= :notable',
            ['type' => $type, 'notable' => self::NOTABLE_POPULARITY],
        )->fetchOne();

        /*
         * The catalog can hold more of this tier than the queue lists: a title
         * crawled before today's export was published, or one whose popularity
         * has risen past the line since. Clamping keeps the bar from reading
         * over 100% and the remainder from going negative.
         */
        $held = min($held, $total);
        $remaining = max($total - $held, 0);

        return [
            'floor' => self::NOTABLE_POPULARITY,
            'total' => $total,
            'crawled' => $held,
            'remaining' => $remaining,
            'percent' => $total > 0 ? round($held / $total * 100, 2) : 0.0,
            'etaHours' => $perMinute > 0 ? round($remaining / $perMinute / 60, 1) : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function filtered(string $queue): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT skipped_reason, COUNT(*) AS n FROM {$queue}
             WHERE skipped_reason IS NOT NULL GROUP BY skipped_reason ORDER BY n DESC",
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['skipped_reason']] = (int) $row['n'];
        }

        return $out;
    }

    /** An unknown type degrades to movies rather than erroring. */
    private function type(Request $request): string
    {
        $type = (string) $request->query->get('type', 'movie');

        return isset(self::QUEUES[$type]) ? $type : 'movie';
    }

    private function isRunning(mixed $lastAdded): bool
    {
        if (!\is_string($lastAdded)) {
            return false;
        }

        return (new \DateTimeImmutable($lastAdded)) > new \DateTimeImmutable(sprintf('-%d minutes', self::IDLE_MINUTES));
    }

    private function atom(mixed $value): ?string
    {
        return \is_string($value) ? (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM) : null;
    }
}
