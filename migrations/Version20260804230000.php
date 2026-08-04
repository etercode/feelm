<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Studios, keywords, where to stream, and what TMDB thinks is like it.
 *
 * All four arrive on the same request the crawler already makes — appended to
 * it rather than fetched separately — so none of this costs an extra call.
 *
 * ---- watch_providers, and the number I got wrong -------------------------
 *
 * I first sized this from three blockbusters and said 81 GB. Sampled properly
 * across six popularity bands it averages 3 kB a title, because most of the
 * catalog has no providers at all and answers `{}`. 2.9 GB for the lot. Stored
 * whole, so a detail page can offer buy and rent as well as streaming.
 *
 * It is the one column here that rots: titles leave services weekly. Whatever
 * refreshes it later matters more than what writes it now.
 *
 * ---- why related titles get a table ---------------------------------------
 *
 * "Related" is currently a search for the same genre sorted by popularity —
 * so anything related to Dune is really just popular science fiction. TMDB
 * answers it properly and gives two answers: `similar` (keywords and genres)
 * and `recommendations` (what people actually watch together). The second is
 * visibly better and both are free, so both are kept and the reader chooses.
 *
 * Rows rather than a json column because this is a join: twelve of each per
 * work, resolved through external_ids to whichever of them we hold. TMDB ids
 * rather than our own, because the title being pointed at may not be crawled
 * yet — storing the id keeps the pointer good for whenever it arrives.
 */
final class Version20260804230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add studios, keywords, watch providers and TMDB related titles';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE works
            ADD COLUMN IF NOT EXISTS companies JSONB DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS keywords JSONB DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS watch_providers JSONB DEFAULT NULL');

        // Containment only — "which works list Warner Bros", "which are tagged
        // dystopia" — so the smaller operator class is the right one.
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_companies
            ON works USING GIN (companies jsonb_path_ops) WHERE deleted_at IS NULL');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_keywords
            ON works USING GIN (keywords jsonb_path_ops) WHERE deleted_at IS NULL');

        $this->addSql('CREATE TABLE IF NOT EXISTS work_related (
            work_id INT NOT NULL,
            kind VARCHAR(12) NOT NULL,
            tmdb_id INT NOT NULL,
            position SMALLINT NOT NULL,
            PRIMARY KEY (work_id, kind, tmdb_id),
            CONSTRAINT fk_work_related_work FOREIGN KEY (work_id)
                REFERENCES works (id) ON DELETE CASCADE
        )');

        // The read is always "this work, this kind, in order".
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_related_lookup
            ON work_related (work_id, kind, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS work_related');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_companies');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_keywords');
        $this->addSql('ALTER TABLE works
            DROP COLUMN IF EXISTS companies,
            DROP COLUMN IF EXISTS keywords,
            DROP COLUMN IF EXISTS watch_providers');
    }
}
