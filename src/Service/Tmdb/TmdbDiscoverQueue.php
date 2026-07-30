<?php

namespace App\Service\Tmdb;

use Doctrine\DBAL\Connection;

/**
 * Dates the crawl queue by walking /discover month by month.
 *
 * The id export says what exists; it does not say when anything came out. This
 * fills that in for a chosen span — one discover page carries twenty ids with
 * their release dates, so a month of releases costs a handful of requests and
 * saves a detail request for every title outside the span.
 *
 * Months, not years: discover stops at 500 pages (10,000 results) per query, and
 * recent years run past 50,000 releases. A month has never come close.
 *
 * Enumeration is resumable — the cursor is a month and a page, kept with the
 * other crawl state on disk.
 */
final class TmdbDiscoverQueue
{
    /** TMDB's hard cap on pagination. */
    private const MAX_PAGES = 500;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Walks months from the cursor, adding ids to the queue with their year.
     *
     * @param callable(string): void|null $log
     *
     * @return array{queued: int, months: int, done: bool, cursor: string}
     */
    public function fill(int $since, int $requestBudget = 400, ?callable $log = null): array
    {
        $say = $log ?? static function (string $message): void {};
        $state = $this->loadCursor($since);

        $queued = 0;
        $months = 0;
        $requests = 0;
        $lastMonth = (new \DateTimeImmutable('first day of this month'))->modify('+1 month');

        while ($requests < $requestBudget) {
            $month = new \DateTimeImmutable(sprintf('%d-%02d-01', $state['year'], $state['month']));
            if ($month > $lastMonth) {
                $state['done'] = true;
                break;
            }

            $page = $this->tmdb->get('/discover/movie', [
                'include_adult' => 'false',
                'sort_by' => 'popularity.desc',
                'page' => $state['page'],
                'primary_release_date.gte' => $month->format('Y-m-01'),
                'primary_release_date.lte' => $month->format('Y-m-t'),
            ]);
            ++$requests;

            $results = \is_array($page['results'] ?? null) ? $page['results'] : [];
            $totalPages = min((int) ($page['total_pages'] ?? 1), self::MAX_PAGES);

            if ([] !== $results) {
                $queued += $this->store($results);
            }

            if ($state['page'] >= $totalPages || [] === $results) {
                $say(sprintf('  %s — %s pages', $month->format('Y-m'), number_format(max($totalPages, 1))));
                ++$months;
                $state['page'] = 1;
                if (12 === $state['month']) {
                    $state['month'] = 1;
                    ++$state['year'];
                } else {
                    ++$state['month'];
                }
            } else {
                ++$state['page'];
            }
        }

        $this->saveCursor($state);

        return [
            'queued' => $queued,
            'months' => $months,
            'done' => (bool) $state['done'],
            'cursor' => sprintf('%d-%02d p%d', $state['year'], $state['month'], $state['page']),
        ];
    }

    /** How many dated titles are queued at or after a year, still to fetch. */
    public function remaining(int $since): int
    {
        return (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM tmdb_movie_ids e
             WHERE e.release_year >= :since
               AND NOT EXISTS (
                   SELECT 1 FROM external_ids x
                   WHERE x.source = 'tmdb' AND x.external_id = e.tmdb_id::text
               )",
            ['since' => $since],
        )->fetchOne();
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function store(array $results): int
    {
        $values = [];
        $params = [];
        $index = 0;

        foreach ($results as $row) {
            if (!\is_array($row) || !isset($row['id'])) {
                continue;
            }
            $date = \is_string($row['release_date'] ?? null) ? $row['release_date'] : '';
            $year = 4 <= \strlen($date) ? (int) substr($date, 0, 4) : null;
            if (null === $year) {
                continue;
            }

            $values[] = sprintf('(:id%1$d, :pop%1$d, :year%1$d)', $index);
            $params['id'.$index] = (int) $row['id'];
            $params['pop'.$index] = isset($row['popularity']) ? (float) $row['popularity'] : 0.0;
            $params['year'.$index] = $year;
            ++$index;
        }

        if ([] === $values) {
            return 0;
        }

        $params['exportedOn'] = (new \DateTimeImmutable())->format('Y-m-d');

        // Adds ids the export missed and dates the ones it already had.
        $this->connection->executeStatement(
            'INSERT INTO tmdb_movie_ids (tmdb_id, popularity, release_year, exported_on)
             SELECT v.id::bigint, v.pop::double precision, v.year::smallint, :exportedOn::date
             FROM (VALUES '.implode(', ', $values).') AS v(id, pop, year)
             ON CONFLICT (tmdb_id) DO UPDATE
             SET release_year = EXCLUDED.release_year,
                 popularity = GREATEST(EXCLUDED.popularity, tmdb_movie_ids.popularity)',
            $params,
        );

        return \count($values);
    }

    /**
     * @return array{year: int, month: int, page: int, since: int, done: bool}
     */
    private function loadCursor(int $since): array
    {
        $path = $this->cursorPath();
        $state = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        // Changing the start year starts the walk over.
        if (!\is_array($state) || ($state['since'] ?? null) !== $since) {
            return ['year' => $since, 'month' => 1, 'page' => 1, 'since' => $since, 'done' => false];
        }

        return [
            'year' => (int) ($state['year'] ?? $since),
            'month' => (int) ($state['month'] ?? 1),
            'page' => (int) ($state['page'] ?? 1),
            'since' => $since,
            'done' => (bool) ($state['done'] ?? false),
        ];
    }

    /**
     * @param array{year: int, month: int, page: int, since: int, done: bool} $state
     */
    private function saveCursor(array $state): void
    {
        $path = $this->cursorPath();
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function cursorPath(): string
    {
        return $this->projectDir.'/data/crawl/discover-queue.json';
    }
}
