<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Countries, keywords and studios become rows, not json.
 *
 * ---- why this changed before anything was backfilled ---------------------
 *
 * They were jsonb columns with GIN indexes, which filters perfectly well:
 * `countries @> '["TR"]'` is fast. The facet is what breaks. Counting
 * "Turkey 1,234" beside the chip means unnesting the array of every matching
 * row — jsonb_array_elements over the result set, a function call per row,
 * with no index able to help. Genres already do this properly: work_genre is
 * a join table and the facet is a plain JOIN and GROUP BY over an index.
 *
 * A filter nobody can count next to is half a filter, so this follows genres.
 *
 * ---- one table, not three -------------------------------------------------
 *
 * Country, keyword and studio are the same shape — a work, a kind of tag, a
 * value — and giving each its own table would be the same index written three
 * times. `kind` keeps them apart, and the next one (network, language) costs
 * nothing but a constant.
 *
 * The index is (kind, value, work_id): every question starts with "which works
 * are tagged X", and the work_id on the end lets the filter be answered from
 * the index without touching the table.
 *
 * Values are stored as text rather than pointing at a lookup table. About 14
 * million rows at ~15 bytes is a couple of hundred megabytes, which is cheaper
 * than the join it would save.
 *
 * ---- the backfill cursor ---------------------------------------------------
 *
 * `countries IS NULL` was doing that job. With the column gone the marker has
 * to be its own fact, so details_synced_at says when a row was last filled in
 * — which also makes it possible to re-sync anything older than a date, which
 * watch providers will need, since those go stale on their own.
 */
final class Version20260804234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move countries, keywords and studios into a tag table';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS work_tag (
            work_id INT NOT NULL,
            kind SMALLINT NOT NULL,
            value VARCHAR(120) NOT NULL,
            PRIMARY KEY (work_id, kind, value),
            CONSTRAINT fk_work_tag_work FOREIGN KEY (work_id)
                REFERENCES works (id) ON DELETE CASCADE
        )');

        // "Which works are tagged X" — work_id last so the filter never has to
        // read the table.
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_tag_lookup
            ON work_tag (kind, value, work_id)');

        $this->addSql('ALTER TABLE works
            ADD COLUMN IF NOT EXISTS details_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Partial: the backfill only ever asks for the rows that are still null.
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_details_unsynced
            ON works (popularity DESC NULLS LAST, id)
            WHERE deleted_at IS NULL AND details_synced_at IS NULL');

        // The json versions never held anything: nothing has been backfilled
        // yet, and the crawler that writes them has not run since they existed.
        $this->addSql('ALTER TABLE works
            DROP COLUMN IF EXISTS countries,
            DROP COLUMN IF EXISTS keywords,
            DROP COLUMN IF EXISTS companies');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE works
            ADD COLUMN IF NOT EXISTS countries JSONB DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS keywords JSONB DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS companies JSONB DEFAULT NULL');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_details_unsynced');
        $this->addSql('ALTER TABLE works DROP COLUMN IF EXISTS details_synced_at');
        $this->addSql('DROP TABLE IF EXISTS work_tag');
    }
}
