<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a title is from.
 *
 * TMDB has sent this on every detail response since the catalog was first
 * crawled; nothing ever read it. `original_language` was standing in for it and
 * is not the same fact — a British film and an American one are both `en`.
 *
 * ISO 3166-1 codes in a jsonb array, not names: a name is a translation and the
 * browser already turns a code into one with Intl.DisplayNames. A list, not one
 * value, because co-productions are normal and choosing a winner would invent
 * an answer the source does not give.
 *
 * jsonb_path_ops rather than the default: this index only ever answers
 * containment — "which works list GB" — and the smaller operator class is about
 * a third the size and faster at exactly that.
 *
 * CONCURRENTLY because works is 1.5 GB and serving traffic.
 */
final class Version20260804200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which countries a title comes from';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE works ADD COLUMN IF NOT EXISTS countries JSONB DEFAULT NULL');

        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_countries
            ON works USING GIN (countries jsonb_path_ops)
            WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_countries');
        $this->addSql('ALTER TABLE works DROP COLUMN IF EXISTS countries');
    }
}
