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
 */
final class CrawlController extends AbstractController
{
    /** How far back to look when working out the current rate. */
    private const RATE_WINDOW_MINUTES = 10;

    /** No recent arrivals for this long means nothing is running. */
    private const IDLE_MINUTES = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkRepository $works,
        private readonly WorkPresenter $presenter,
    ) {
    }

    #[Route('/api/crawl/status', name: 'api_crawl_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $queue = $this->connection->executeQuery(
            'SELECT
                COUNT(*)                                        AS total,
                COUNT(*) FILTER (WHERE crawled_at IS NOT NULL)  AS crawled,
                COUNT(*) FILTER (WHERE crawled_at IS NULL)      AS remaining,
                MAX(crawled_at)                                 AS last_crawled_at
             FROM tmdb_movie_ids',
        )->fetchAssociative() ?: [];

        $total = (int) ($queue['total'] ?? 0);
        $crawled = (int) ($queue['crawled'] ?? 0);
        $remaining = (int) ($queue['remaining'] ?? 0);

        // Rate from what actually landed, rather than from anything the command
        // reports — this stays true whether or not a crawl is running.
        $recent = (int) $this->connection->executeQuery(
            sprintf(
                "SELECT COUNT(*) FROM works WHERE added_at > NOW() - INTERVAL '%d minutes'",
                self::RATE_WINDOW_MINUTES,
            ),
        )->fetchOne();

        $perMinute = $recent / self::RATE_WINDOW_MINUTES;
        $lastAdded = $this->connection->executeQuery('SELECT MAX(added_at) FROM works')->fetchOne();

        return $this->json([
            'total' => $total,
            'crawled' => $crawled,
            'remaining' => $remaining,
            'percent' => $total > 0 ? round($crawled / $total * 100, 2) : 0.0,
            'inCatalog' => $this->works->countByType('movie'),
            'perMinute' => round($perMinute, 1),
            'perSecond' => round($perMinute / 60, 2),
            // Null rather than infinity when nothing is moving: the frontend
            // shows "—" instead of a number that would be a guess.
            'etaHours' => $perMinute > 0 ? round($remaining / $perMinute / 60, 1) : null,
            'running' => $this->isRunning($lastAdded),
            'lastAddedAt' => $this->atom($lastAdded),
            'lastCrawledAt' => $this->atom($queue['last_crawled_at'] ?? null),
        ]);
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
        $limit = max(1, min(60, (int) $request->query->get('limit', 24)));
        $page = max(1, (int) $request->query->get('page', 1));

        $total = $this->works->countByType('movie');
        $pages = (int) ceil($total / $limit);

        $works = $this->works->createQueryBuilder('w')
            ->where('w.type = :type')
            ->setParameter('type', 'movie')
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
            'items' => array_map(fn ($work) => $this->presenter->one($work), $works),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
        ]);
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
