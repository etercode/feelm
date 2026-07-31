<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a work be hidden from the catalog without being destroyed.
 *
 * Entries, reviews and seen marks all cascade from works, so a plain DELETE of
 * one bad or duplicated row takes other people's scores and writing with it,
 * silently. This gives the admin something reversible to reach for instead.
 *
 * The index is partial. Practically every row is NULL, so an index over the
 * whole column would be 412k entries serving nothing: reads that ask for
 * "not deleted" are better off with the indexes they already use. This one
 * exists so the admin's "show me the hidden ones" stays instant, and it costs
 * almost nothing to keep.
 */
final class Version20260731160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add works.deleted_at so a work can be hidden rather than destroyed';
    }

    public function up(Schema $schema): void
    {
        // No DC2Type comment: DBAL 4 infers datetime_immutable without one, and
        // adding it puts schema:validate permanently out of sync — no other
        // timestamp column in this schema carries one.
        $this->addSql('ALTER TABLE works ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_work_deleted_at ON works (deleted_at) WHERE (deleted_at IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_work_deleted_at');
        $this->addSql('ALTER TABLE works DROP deleted_at');
    }
}
