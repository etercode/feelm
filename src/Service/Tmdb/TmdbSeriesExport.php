<?php

namespace App\Service\Tmdb;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * TMDB's daily list of every TV series id — the movie export's sibling.
 *
 * Same reasoning as {@see TmdbIdExport}: /discover cannot enumerate a catalog
 * this size, and the export can. It is smaller than the movie one (228k ids
 * against 727k) and carries the same `popularity`, which is what lets the crawl
 * fetch the shows people search for first.
 *
 * The difference is `skipped_reason`. Anyone may add a series to TMDB, so a
 * good share of the tail is a home video or an abandoned stub — see
 * {@see \App\Service\Catalog\SeriesQualityGate}. A rejected id is marked
 * crawled like any other, so it leaves the queue and is never fetched twice,
 * but the reason stays on the row: the filter is then something you can audit
 * and reverse rather than a silent hole.
 *
 * https://developer.themoviedb.org/docs/daily-id-exports
 */
final class TmdbSeriesExport
{
    private const URL = 'http://files.tmdb.org/p/exports/tv_series_ids_%s.json.gz';

    /** Rows per INSERT round trip. */
    private const BATCH = 2000;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function exportedOn(): ?string
    {
        $value = $this->connection->executeQuery('SELECT MAX(exported_on) FROM tmdb_series_ids')->fetchOne();

        return \is_string($value) ? $value : null;
    }

    public function count(): int
    {
        return (int) $this->connection->executeQuery('SELECT COUNT(*) FROM tmdb_series_ids')->fetchOne();
    }

    /**
     * How many queued ids are neither crawled nor already in the catalog.
     *
     * @param float $minPopularity ignore ids below this, as the crawl does
     */
    public function remaining(float $minPopularity = 0.0): int
    {
        return (int) $this->connection->executeQuery(
            $this->todoSql('COUNT(*)', $minPopularity),
            $minPopularity > 0 ? ['pop' => $minPopularity] : [],
        )->fetchOne();
    }

    /**
     * The next ids to fetch: exported, not yet stored, most popular first.
     *
     * The anti-join against external_ids is what makes this safe to run twice
     * or interrupt — a title leaves the queue by virtue of existing, so nothing
     * has to be remembered between runs.
     *
     * @return list<int>
     */
    public function nextIds(int $limit, float $minPopularity = 0.0): array
    {
        $params = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if ($minPopularity > 0) {
            $params['pop'] = $minPopularity;
        }

        $sql = $this->todoSql('e.tmdb_id', $minPopularity).' ORDER BY e.popularity DESC, e.tmdb_id LIMIT :limit';
        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /**
     * Marks ids done, whether they were stored, rejected or gone from TMDB.
     *
     * @param array<int, string|null> $reasons tmdb id => rejection reason, null when kept
     */
    public function markCrawled(array $reasons): void
    {
        if ([] === $reasons) {
            return;
        }

        // Kept ids share one statement; rejections are grouped by reason, of
        // which there are a handful. Either way it is a few round trips per
        // window rather than one per title.
        $byReason = [];
        foreach ($reasons as $tmdbId => $reason) {
            $byReason[$reason ?? ''][] = (int) $tmdbId;
        }

        foreach ($byReason as $reason => $ids) {
            $this->connection->executeStatement(
                'UPDATE tmdb_series_ids SET crawled_at = NOW(), skipped_reason = :reason WHERE tmdb_id IN (:ids)',
                ['reason' => '' === $reason ? null : $reason, 'ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }
    }

    /**
     * What the crawl threw away and why, commonest first.
     *
     * @return array<string, int>
     */
    public function skipCounts(): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT skipped_reason, COUNT(*) AS n FROM tmdb_series_ids
             WHERE skipped_reason IS NOT NULL GROUP BY skipped_reason ORDER BY n DESC',
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['skipped_reason']] = (int) $row['n'];
        }

        return $out;
    }

    /** Forgets one id's rejection, so the next run fetches it again. */
    public function requeue(string $reason): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE tmdb_series_ids SET crawled_at = NULL, skipped_reason = NULL WHERE skipped_reason = :reason',
            ['reason' => $reason],
        );
    }

    /**
     * Downloads a day's export and loads it into the queue. TMDB publishes
     * around 08:00 UTC, so today's file may not exist yet — fall back a day at
     * a time.
     *
     * @param callable(string): void|null $log
     *
     * @return array{date: string, ids: int}
     */
    public function refresh(?callable $log = null): array
    {
        $say = $log ?? static function (string $message): void {};

        foreach ([0, 1, 2] as $daysBack) {
            $day = new \DateTimeImmutable(sprintf('-%d days', $daysBack));
            $url = sprintf(self::URL, $day->format('m_d_Y'));

            $handle = @gzopen($url, 'rb');
            if (false === $handle) {
                $say(sprintf('No series export for %s yet.', $day->format('Y-m-d')));
                continue;
            }

            $say(sprintf('Reading %s…', $url));
            $loaded = $this->load($handle, $day->format('Y-m-d'));
            gzclose($handle);

            $say(sprintf('%s series ids in the export.', number_format($loaded)));

            return ['date' => $day->format('Y-m-d'), 'ids' => $loaded];
        }

        throw new \RuntimeException('Could not download a TMDB series export for the last three days.');
    }

    /**
     * "Still to do": exported, never attempted, and not already in the catalog.
     */
    private function todoSql(string $select, float $minPopularity): string
    {
        // tmdb_tv, not tmdb: a third of these ids also name a film, and matching
        // in the movie id space would mark 78k series as already crawled.
        $sql = "SELECT {$select} FROM tmdb_series_ids e
                WHERE e.crawled_at IS NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM external_ids x
                    WHERE x.source = 'tmdb_tv' AND x.external_id = e.tmdb_id::text
                  )";

        return $minPopularity > 0 ? $sql.' AND e.popularity >= :pop' : $sql;
    }

    /**
     * @param resource $handle
     */
    private function load($handle, string $exportedOn): int
    {
        $batch = [];
        $loaded = 0;

        while (false !== ($line = gzgets($handle))) {
            $row = json_decode(trim($line), true);
            if (!\is_array($row) || !isset($row['id'])) {
                continue;
            }

            $batch[] = [
                'id' => (int) $row['id'],
                'popularity' => isset($row['popularity']) ? (float) $row['popularity'] : 0.0,
            ];

            if (\count($batch) >= self::BATCH) {
                $loaded += $this->upsert($batch, $exportedOn);
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $loaded += $this->upsert($batch, $exportedOn);
        }

        return $loaded;
    }

    /**
     * @param list<array{id: int, popularity: float}> $batch
     */
    private function upsert(array $batch, string $exportedOn): int
    {
        $values = [];
        $params = ['exportedOn' => $exportedOn];

        foreach ($batch as $index => $row) {
            $values[] = sprintf('(:id%1$d, :pop%1$d)', $index);
            $params['id'.$index] = $row['id'];
            $params['pop'.$index] = $row['popularity'];
        }

        /*
         * Popularity is refreshed but crawled_at is not touched: re-running the
         * download must not re-queue everything already fetched. A rejected id
         * likewise stays rejected until `--requeue` asks for it back.
         */
        $sql = 'INSERT INTO tmdb_series_ids (tmdb_id, popularity, exported_on)
                SELECT v.id::bigint, v.pop::double precision, :exportedOn::date
                FROM (VALUES '.implode(', ', $values).') AS v(id, pop)
                ON CONFLICT (tmdb_id) DO UPDATE
                SET popularity = EXCLUDED.popularity,
                    exported_on = EXCLUDED.exported_on';

        $this->connection->executeStatement($sql, $params);

        return \count($batch);
    }
}
