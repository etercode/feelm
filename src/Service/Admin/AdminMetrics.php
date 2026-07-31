<?php

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;

/**
 * The numbers on the admin overview.
 *
 * All of it in one round trip on purpose. Counting works, people and credits
 * separately is three sequential scans of the three largest tables in the
 * database; together they are one 190ms query, which a dashboard can afford and
 * three of them cannot.
 *
 * These are exact counts rather than pg_class estimates. The estimate is only
 * as fresh as the last autovacuum, and during a crawl that is a number visibly
 * behind the one on the crawler page.
 */
final class AdminMetrics
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{
     *     works: int, people: int, credits: int, reviews: int, entries: int,
     *     genres: int, follows: int, worksByType: array<string, int>,
     * }
     */
    public function totals(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT (SELECT COUNT(*) FROM works) AS works,
                    (SELECT COUNT(*) FROM people) AS people,
                    (SELECT COUNT(*) FROM credits) AS credits,
                    (SELECT COUNT(*) FROM reviews) AS reviews,
                    (SELECT COUNT(*) FROM entries) AS entries,
                    (SELECT COUNT(*) FROM genres) AS genres,
                    (SELECT COUNT(*) FROM follows) AS follows',
        ) ?: [];

        return [
            'works' => (int) ($row['works'] ?? 0),
            'people' => (int) ($row['people'] ?? 0),
            'credits' => (int) ($row['credits'] ?? 0),
            'reviews' => (int) ($row['reviews'] ?? 0),
            'entries' => (int) ($row['entries'] ?? 0),
            'genres' => (int) ($row['genres'] ?? 0),
            'follows' => (int) ($row['follows'] ?? 0),
            'worksByType' => $this->worksByType(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function worksByType(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, COUNT(*) AS n FROM works GROUP BY type ORDER BY n DESC',
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * How much arrived recently, so the overview can say whether anything is
     * still moving without reading the crawler's state file.
     *
     * @return array{worksToday: int, worksThisWeek: int, usersThisWeek: int, reviewsThisWeek: int}
     */
    public function recentActivity(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT (SELECT COUNT(*) FROM works WHERE added_at >= NOW() - INTERVAL '1 day') AS works_today,
                    (SELECT COUNT(*) FROM works WHERE added_at >= NOW() - INTERVAL '7 days') AS works_week,
                    (SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL '7 days' AND deleted_at IS NULL) AS users_week,
                    (SELECT COUNT(*) FROM reviews WHERE created_at >= NOW() - INTERVAL '7 days') AS reviews_week",
        ) ?: [];

        return [
            'worksToday' => (int) ($row['works_today'] ?? 0),
            'worksThisWeek' => (int) ($row['works_week'] ?? 0),
            'usersThisWeek' => (int) ($row['users_week'] ?? 0),
            'reviewsThisWeek' => (int) ($row['reviews_week'] ?? 0),
        ];
    }
}
