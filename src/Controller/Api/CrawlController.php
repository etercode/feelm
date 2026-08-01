<?php

namespace App\Controller\Api;

use App\Presenter\WorkPresenter;
use App\Repository\WorkRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

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

    /** Which queue table backs which type. */
    private const QUEUES = [
        'movie' => 'tmdb_movie_ids',
        'series' => 'tmdb_series_ids',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkRepository $works,
        private readonly WorkPresenter $presenter,
    ) {
    }

    #[Route('/api/crawl/status', name: 'api_crawl_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $type = $this->type($request);
        $queue = self::QUEUES[$type];

        $counts = $this->connection->executeQuery(
            "SELECT
                COUNT(*)                                        AS total,
                COUNT(*) FILTER (WHERE crawled_at IS NOT NULL)  AS crawled,
                COUNT(*) FILTER (WHERE crawled_at IS NULL)      AS remaining,
                MAX(crawled_at)                                 AS last_crawled_at
             FROM {$queue}",
        )->fetchAssociative() ?: [];

        $total = (int) ($counts['total'] ?? 0);
        $crawled = (int) ($counts['crawled'] ?? 0);

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
            'remaining' => (int) ($counts['remaining'] ?? 0),
            'percent' => $total > 0 ? round($crawled / $total * 100, 2) : 0.0,
            'inCatalog' => $this->works->countByType($type),
            'perMinute' => round($perMinute, 1),
            'perSecond' => round($perMinute / 60, 2),
            // Null rather than infinity when nothing is moving: the frontend
            // shows "—" instead of a number that would be a guess.
            'etaHours' => $perMinute > 0 ? round(($total - $crawled) / $perMinute / 60, 1) : null,
            'running' => $this->isRunning($lastAdded),
            'lastAddedAt' => $this->atom($lastAdded),
            'lastCrawledAt' => $this->atom($counts['last_crawled_at'] ?? null),
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

        return $this->json($payload);
    }

    /**
     * The most recently crawled titles, newest first.
     *
     * Paged rather than capped: the queue is three quarters of a million rows
     * and the whole point is to be able to look through what arrived.
     */
    #[Route('/api/crawl/recent', name: 'api_crawl_recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
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
