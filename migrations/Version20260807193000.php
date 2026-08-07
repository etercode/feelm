<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `works.adult` — titles hidden for being 18+ rather than for being wrong.
 *
 * Hand-written, like every migration in this repository. `migrations:diff`
 * cannot see the partial indexes here (it has no vocabulary for a WHERE
 * clause) and would offer to drop the DBAL-written tables next to whatever it
 * generated; see the note on WorkTag.
 *
 * The index is partial on purpose. Almost nothing is flagged — a few thousand
 * titles out of 1.2 million — so an index over the whole column would be a
 * million rows of `false` to answer a question only ever asked about `true`.
 * The read paths ask `adult = false`, which Postgres answers from a sequential
 * scan it was doing anyway; the moderation screens ask `adult = true`, and
 * that is what this serves.
 */
final class Version20260807193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add works.adult for hand-flagged 18+ titles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE works ADD adult BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('CREATE INDEX idx_works_adult ON works (id) WHERE adult');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_works_adult');
        $this->addSql('ALTER TABLE works DROP adult');
    }
}
