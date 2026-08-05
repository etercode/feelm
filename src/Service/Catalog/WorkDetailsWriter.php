<?php

namespace App\Service\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The parts of a title that do not live on the works row: its tags and what
 * TMDB says is like it.
 *
 * These writes belonged to CatalogBackfillDetailsCommand, which was the only
 * thing that had ever needed them — it was a one-off pass over the whole
 * catalogue. Then the daily changes sync arrived and needed exactly the same
 * writes for exactly the same payload, and the split showed up as a hole: the
 * sync fetched keywords, countries, studios and similars in its response and
 * dropped them, because the code that stores them lived in a command it does
 * not call. A film re-genred on TMDB got a fresh overview and stale keywords,
 * for ever, since the backfill only ever looks at rows it has not stamped.
 *
 * So it lives here, and both callers get all of it.
 *
 * Everything is replace-then-insert rather than merge. The fetch is the current
 * answer in full, and a keyword or a studio that has been corrected upstream
 * must not survive here because it was true once.
 */
final class WorkDetailsWriter
{
    public const TAG_COUNTRY = 1;
    public const TAG_KEYWORD = 2;
    public const TAG_COMPANY = 3;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Countries, keywords and studios.
     *
     * @param list<string>         $countries
     * @param array<string, mixed> $extras
     */
    public function tags(int $workId, array $countries, array $extras): void
    {
        $this->connection->executeStatement('DELETE FROM work_tag WHERE work_id = :id', ['id' => $workId]);

        $groups = [
            self::TAG_COUNTRY => $countries,
            self::TAG_KEYWORD => $extras['keywords'] ?? [],
            self::TAG_COMPANY => $extras['companies'] ?? [],
        ];

        /*
         * One statement, not one per tag.
         *
         * A title carries about thirteen of these and twenty-four related rows,
         * and writing them individually meant nearly forty round trips to serve
         * a single HTTP fetch — the backfill ran at 3/s against a client that
         * can fetch at twenty. Batched, it is three statements a title whatever
         * the counts are.
         */
        $rows = [];
        $params = [];

        foreach ($groups as $kind => $values) {
            foreach ((array) $values as $value) {
                $value = mb_substr(trim((string) $value), 0, 120);
                if ('' === $value) {
                    continue;
                }

                $i = \count($rows);
                $rows[] = "(:w{$i}, :k{$i}, :v{$i})";
                $params["w{$i}"] = $workId;
                $params["k{$i}"] = $kind;
                $params["v{$i}"] = $value;
            }
        }

        if ([] === $rows) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO work_tag (work_id, kind, value) VALUES '.implode(', ', $rows)
            .' ON CONFLICT (work_id, kind, value) DO NOTHING',
            $params,
        );
    }

    /**
     * What TMDB says is like this one.
     *
     * TMDB ids, not ours. The title being pointed at may not be crawled yet,
     * and an id keeps the pointer good for whenever it arrives — the read side
     * resolves through external_ids and simply shows fewer.
     *
     * @param array<string, mixed> $extras
     */
    public function related(int $workId, array $extras): void
    {
        $this->connection->executeStatement('DELETE FROM work_related WHERE work_id = :id', ['id' => $workId]);

        $rows = [];
        $params = [];

        foreach (['similar' => $extras['similar'] ?? null, 'recommended' => $extras['recommended'] ?? null] as $kind => $ids) {
            foreach ((array) $ids as $position => $tmdbId) {
                $i = \count($rows);
                $rows[] = "(:w{$i}, :k{$i}, :t{$i}, :p{$i})";
                $params["w{$i}"] = $workId;
                $params["k{$i}"] = $kind;
                $params["t{$i}"] = (int) $tmdbId;
                $params["p{$i}"] = $position;
            }
        }

        if ([] === $rows) {
            return;
        }

        // Same reason as the tags: one statement rather than twenty-four.
        $this->connection->executeStatement(
            'INSERT INTO work_related (work_id, kind, tmdb_id, position) VALUES '.implode(', ', $rows)
            .' ON CONFLICT (work_id, kind, tmdb_id) DO NOTHING',
            $params,
        );
    }

    /**
     * Say this title's details are current as of now.
     *
     * The backfill stamps this itself, as one column of a wider update it is
     * already making. The sync has no such update, so it says so here.
     */
    public function stamp(int $workId): void
    {
        $this->connection->executeStatement(
            'UPDATE works SET details_synced_at = NOW() WHERE id = :id',
            ['id' => $workId],
        );
    }
}
