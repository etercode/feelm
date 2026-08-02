<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A second, smaller full-text index covering only titles anybody has heard of.
 *
 * Relevance ranking works over the most popular few thousand matches, and
 * finding those means visiting every match: "ma" matches 305,242 rows, which is
 * 1.4 seconds on the server before ranking starts. That cost lands on every
 * keystroke, because the search overlay fires from the second character.
 *
 * Only 234,241 of 876,074 works have any popularity at all, and a row with none
 * can never reach the top of a pool sorted by it while a popular one is
 * waiting. So the same pool can be found in an index a quarter the size, and
 * the answer is identical whenever the smaller index fills the pool on its own
 * — which WorkSearch checks rather than assumes.
 *
 * CONCURRENTLY: the table is 1.5 GB and serving traffic. That forbids a
 * transaction, hence the disabled wrapper below.
 */
final class Version20260803120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a partial full-text index over works with any popularity';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_search_popular
            ON works USING gin (search_vector)
            WHERE popularity >= 1 AND deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_search_popular');
    }
}
