<?php

namespace App\Service\Catalog;

use App\Entity\WorkRating;
use Doctrine\DBAL\Connection;

/**
 * Imports IMDb ratings from IMDb's own published dataset.
 *
 * IMDb has no ratings API and blocks scripted page requests, but it does publish
 * `title.ratings.tsv.gz` daily — tconst, averageRating, numVotes for every rated
 * title. One 8 MB download replaces a million requests, and it is the only route
 * that is both complete and allowed. Non-commercial use only:
 * https://developer.imdb.com/non-commercial-datasets/
 *
 * The join key is the IMDb id the crawler already stores in external_ids.
 * Writing a rating row is all this has to do — works.external_score follows
 * through a trigger.
 *
 * Scaling note: the file is streamed and only rows matching an id we hold are
 * kept, so memory is bounded by the catalog rather than by IMDb. Past a few
 * million works, load the whole file into a staging table with COPY and join in
 * the database instead.
 */
final class ImdbRatingsImporter
{
    public const DATASET_URL = 'https://datasets.imdbws.com/title.ratings.tsv.gz';

    /** Rows per UPDATE round trip. */
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param callable(string): void|null $log
     *
     * @return array{known: int, matched: int, updated: int, scanned: int}
     */
    public function import(?string $localFile = null, ?callable $log = null): array
    {
        $say = $log ?? static function (string $message): void {};

        $ids = $this->knownImdbIds();
        if ([] === $ids) {
            $say('No IMDb ids stored yet — crawl some titles first.');

            return ['known' => 0, 'matched' => 0, 'updated' => 0, 'scanned' => 0];
        }
        $say(sprintf('%s titles have an IMDb id.', number_format(\count($ids))));

        $path = $localFile ?? $this->download($say);

        $scanned = 0;
        $matched = 0;
        $updated = 0;
        /** @var list<array{id: int, rating: float, votes: int}> $batch */
        $batch = [];

        $handle = gzopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Could not read %s.', $path));
        }

        try {
            gzgets($handle); // header row

            while (false !== ($line = gzgets($handle))) {
                ++$scanned;
                $columns = explode("\t", rtrim($line, "\r\n"));
                if (3 !== \count($columns)) {
                    continue;
                }

                [$tconst, $rating, $votes] = $columns;
                if (!isset($ids[$tconst])) {
                    continue;
                }

                ++$matched;
                $batch[] = [
                    'id' => $ids[$tconst],
                    'rating' => (float) $rating,
                    'votes' => (int) $votes,
                ];

                if (\count($batch) >= self::BATCH) {
                    $updated += $this->upsert($batch);
                    $batch = [];
                }
            }
        } finally {
            gzclose($handle);
            if (null === $localFile) {
                @unlink($path);
            }
        }

        if ([] !== $batch) {
            $updated += $this->upsert($batch);
        }

        return ['known' => \count($ids), 'matched' => $matched, 'updated' => $updated, 'scanned' => $scanned];
    }

    /**
     * IMDb id => work id, for every work we could match.
     *
     * @return array<string, int>
     */
    private function knownImdbIds(): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT external_id, work_id FROM external_ids WHERE source = :source',
            ['source' => 'imdb'],
        )->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row['external_id']] = (int) $row['work_id'];
        }

        return $ids;
    }

    /** @param callable(string): void $say */
    private function download(callable $say): string
    {
        $target = tempnam(sys_get_temp_dir(), 'imdb-ratings-').'.tsv.gz';
        $say('Downloading '.self::DATASET_URL.'…');

        $source = fopen(self::DATASET_URL, 'rb');
        if (false === $source) {
            throw new \RuntimeException('Could not reach the IMDb dataset.');
        }

        $sink = fopen($target, 'wb');
        if (false === $sink) {
            fclose($source);
            throw new \RuntimeException('Could not open a temporary file for the dataset.');
        }

        $bytes = stream_copy_to_stream($source, $sink);
        fclose($source);
        fclose($sink);

        $say(sprintf('Downloaded %s MB.', number_format($bytes / 1_048_576, 1)));

        return $target;
    }

    /**
     * @param list<array{id: int, rating: float, votes: int}> $batch
     */
    private function upsert(array $batch): int
    {
        $values = [];
        $params = [];

        foreach ($batch as $index => $row) {
            $values[] = sprintf('(:work%1$d, :rating%1$d, :votes%1$d)', $index);
            $params['work'.$index] = $row['id'];
            $params['rating'.$index] = number_format($row['rating'], 2, '.', '');
            $params['votes'.$index] = $row['votes'];
        }

        /*
         * One statement per batch, and re-running it just refreshes the numbers.
         * The casts are load-bearing: bound parameters arrive as text, so
         * without them Postgres types the VALUES columns as text and refuses.
         */
        $sql = 'INSERT INTO work_ratings (work_id, source, rating, scale, votes, updated_at)
                SELECT v.work_id::int, :source, v.rating::numeric, 10, v.votes::int, NOW()
                FROM (VALUES '.implode(', ', $values).') AS v(work_id, rating, votes)
                ON CONFLICT (work_id, source) DO UPDATE
                SET rating = EXCLUDED.rating,
                    votes = EXCLUDED.votes,
                    updated_at = EXCLUDED.updated_at';

        $params['source'] = WorkRating::SOURCE_IMDB;

        return (int) $this->connection->executeStatement($sql, $params);
    }
}
