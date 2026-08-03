<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sorting a type by popularity, which is what most of the site does.
 *
 * There was an index for `(type, external_score)` and one for `(type, added_at)`
 * but none for `(type, popularity)` — and popularity is the default sort for
 * the home rails, for every browse page and for search with no query. Without
 * it, asking for the twenty-eight most popular movies is a parallel sequential
 * scan of 807,000 rows and a top-N sort: 747ms measured on production, on an
 * idle server. The home page draws four rails, so it paid that four times and
 * took three and a half seconds to answer one request.
 *
 * Column order matters and matches the query rather than intuition: `type`
 * first because it is the equality, `popularity DESC NULLS LAST` second because
 * it is the ordering, `id DESC` third because it is the tiebreak that makes
 * paging stable. Postgres can then walk the index and stop at the limit instead
 * of reading the table to sort it.
 *
 * NULLS LAST is written out for the same reason. It is not the default for DESC
 * — Postgres puts nulls first — and an index whose null ordering disagrees with
 * the query's cannot serve it.
 *
 * CONCURRENTLY: the table is 1.5 GB and serving traffic.
 */
final class Version20260803210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index works by type and popularity, the site default sort';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_type_popularity
            ON works (type, popularity DESC NULLS LAST, id DESC)
            WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_type_popularity');
    }
}
