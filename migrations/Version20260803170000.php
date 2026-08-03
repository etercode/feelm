<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Counts titles worth crawling, for the crawler page.
 *
 * That page reported progress against every id in TMDB's export — 1.2 million,
 * of which about a million are shorts, industrial films and festival entries
 * the crawl deliberately skips. So it read 59% and 28 hours left for a job that
 * was four fifths done and had four hours to run.
 *
 * Telling the truth means counting how many of the popular tier are already
 * held, and the queue's own `crawled_at` cannot answer: it is stamped when a
 * whole run finishes, and the runs are batches of two thousand against a
 * backlog of a million, so it reads 13,324 where the real figure is 164,612.
 * The works table is the only honest source, and counting 165,000 rows of it
 * took two seconds — on an endpoint the page polls every ten.
 *
 * `popularity >= 1` is the same line the partial full-text index draws for a
 * title worth ranking in search, so the two agree on what "notable" means.
 *
 * CONCURRENTLY: the table is 1.5 GB and serving traffic. That forbids a
 * transaction, hence the disabled wrapper below.
 */
final class Version20260803170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index works by type over the popular tier, for crawl progress';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_notable
            ON works (type)
            WHERE popularity >= 1 AND deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_notable');
    }
}
