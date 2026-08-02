<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a title find the rest of its collection.
 *
 * The detail page has a strip for the other films in a series — the Alien
 * films, the Bourne films — and it was built from whatever the browser happened
 * to have cached from the front page. So it appeared for a sequel popular
 * enough to be in a rail and was silently absent for every other, which is the
 * worst of both: a feature that looks broken rather than missing.
 *
 * Answering it from the database instead needs this index. Only 21,621 of
 * 728,026 works belong to a collection at all, so it is partial — 3% of the
 * rows, and the other 97% cost nothing to keep out of it.
 */
final class Version20260802090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index works by collection name so siblings can be looked up';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_work_collection ON works ((extra->'collection'->>'name'))
            WHERE (extra->'collection'->>'name') IS NOT NULL AND deleted_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_work_collection');
    }
}
