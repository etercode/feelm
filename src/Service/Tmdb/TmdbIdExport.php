<?php

namespace App\Service\Tmdb;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * TMDB's daily list of every movie id.
 *
 * `/discover` cannot enumerate the whole catalog — it stops at 500 pages per
 * query, its ordering shifts underneath a long crawl as titles are added, and a
 * film with no release date never lands in a date window. The export has no such
 * holes: one gzipped file a day, one JSON object per line, every id TMDB knows.
 *
 * It costs one download instead of ~60,000 discover calls, and it carries
 * `popularity`, which is what lets the crawl fetch the titles people search for
 * before the ones nobody does.
 *
 * https://developer.themoviedb.org/docs/daily-id-exports
 */
final class TmdbIdExport
{
    private const URL = 'http://files.tmdb.org/p/exports/movie_ids_%s.json.gz';

    /** Rows per INSERT round trip. */
    private const BATCH = 2000;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function exportedOn(): ?string
    {
        $value = $this->connection->executeQuery('SELECT MAX(exported_on) FROM tmdb_movie_ids')->fetchOne();

        return \is_string($value) ? $value : null;
    }

    public function count(): int
    {
        return (int) $this->connection->executeQuery('SELECT COUNT(*) FROM tmdb_movie_ids')->fetchOne();
    }

    /**
     * How many queued ids are not in the catalog yet.
     *
     * With a year, only rows that have been dated count — an export row nobody
     * has dated could be from any year, and guessing would silently crawl the
     * decades the year filter was meant to skip.
     */
    public function remaining(?int $since = null, ?float $minPopularity = null): int
    {
        $sql = "SELECT COUNT(*) FROM tmdb_movie_ids e
                WHERE e.crawled_at IS NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM external_ids x
                    WHERE x.source = 'tmdb' AND x.external_id = e.tmdb_id::text
                )";
        $params = [];

        if (null !== $since) {
            $sql .= ' AND e.release_year >= :since';
            $params['since'] = $since;
        }

        if (null !== $minPopularity) {
            $sql .= ' AND e.popularity >= :minPopularity';
            $params['minPopularity'] = $minPopularity;
        }

        return (int) $this->connection->executeQuery($sql, $params)->fetchOne();
    }

    /**
     * The next ids to fetch: exported, not yet in the catalog, most popular
     * first. An anti-join, so nothing needs remembering between runs — a title
     * leaves the queue by virtue of existing.
     *
     * `$minPopularity` is what makes a backfill finish. Ordering by popularity
     * already puts the recognisable titles first, but a run has no idea when it
     * has passed them — the tail of any decade is tens of thousands of shorts
     * and industrial films nobody will search for. A floor turns "crawl the
     * 1970s" into a job with an end, and 1 is the same line the partial search
     * index draws for a title worth ranking.
     *
     * @return list<int>
     */
    public function nextIds(int $limit, ?int $since = null, ?float $minPopularity = null): array
    {
        // crawled_at narrows the scan; the anti-join is the correctness net.
        $sql = "SELECT e.tmdb_id FROM tmdb_movie_ids e
                WHERE e.crawled_at IS NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM external_ids x
                    WHERE x.source = 'tmdb' AND x.external_id = e.tmdb_id::text
                )";
        $params = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if (null !== $since) {
            $sql .= ' AND e.release_year >= :since';
            $params['since'] = $since;
            $types['since'] = ParameterType::INTEGER;
        }

        if (null !== $minPopularity) {
            $sql .= ' AND e.popularity >= :minPopularity';
            $params['minPopularity'] = $minPopularity;
        }

        $sql .= ' ORDER BY e.popularity DESC, e.tmdb_id LIMIT :limit';

        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /**
     * Marks ids as done, whether they were stored or turned out to be gone.
     *
     * @param list<int> $tmdbIds
     */
    public function markCrawled(array $tmdbIds): void
    {
        if ([] === $tmdbIds) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE tmdb_movie_ids SET crawled_at = NOW() WHERE tmdb_id IN (:ids)',
            ['ids' => $tmdbIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /**
     * Downloads a day's export and loads it into the staging table. TMDB
     * publishes around 08:00 UTC, so today's file may not exist yet — fall back
     * a day at a time.
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
                $say(sprintf('No export for %s yet.', $day->format('Y-m-d')));
                continue;
            }

            $say(sprintf('Reading %s…', $url));
            $loaded = $this->load($handle, $day->format('Y-m-d'));
            gzclose($handle);

            $say(sprintf('%s ids in the export.', number_format($loaded)));

            return ['date' => $day->format('Y-m-d'), 'ids' => $loaded];
        }

        throw new \RuntimeException('Could not download a TMDB id export for the last three days.');
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
     * @param list<array{id: int, popularity: float|null}> $batch
     */
    private function upsert(array $batch, string $exportedOn): int
    {
        $values = [];
        $params = ['exportedOn' => $exportedOn];

        foreach ($batch as $index => $row) {
            $values[] = sprintf('(:id%1$d, :pop%1$d)', $index);
            $params['id'.$index] = $row['id'];
            $params['pop'.$index] = $row['popularity'] ?? 0;
        }

        // Casts because bound parameters arrive as text.
        $sql = 'INSERT INTO tmdb_movie_ids (tmdb_id, popularity, exported_on)
                SELECT v.id::bigint, v.pop::double precision, :exportedOn::date
                FROM (VALUES '.implode(', ', $values).') AS v(id, pop)
                ON CONFLICT (tmdb_id) DO UPDATE
                SET popularity = EXCLUDED.popularity,
                    exported_on = EXCLUDED.exported_on';

        $this->connection->executeStatement($sql, $params);

        return \count($batch);
    }
}
