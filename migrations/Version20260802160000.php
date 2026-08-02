<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets an episode keep its whole name.
 *
 * Episode and season titles were varchar(255), which is a MySQL habit rather
 * than a decision: in PostgreSQL varchar(n) and text are the same varlena with
 * the same cost, so the limit bought nothing and cost real data. Anime and
 * light-novel episodes routinely run past it — series 96444, Interspecies
 * Reviewers, carries one long enough that the insert failed outright and took
 * the whole series down with it.
 *
 * varchar(n) -> text is binary-coercible, so this rewrites neither the 4.3M-row
 * episodes heap nor any index: measured at 14ms on this server, and catalog-only
 * work does not grow with the row count. It still takes ACCESS EXCLUSIVE though,
 * so lock_timeout keeps it from queueing behind a long read and stalling the
 * site — better to fail and be re-run than to hold the door shut.
 *
 * Already-truncated rows stay truncated. Widening the column cannot recover text
 * that was never stored; those titles come back only on a re-crawl.
 */
final class Version20260802160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store episode and season titles as TEXT so long ones survive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '5s'");
        $this->addSql('ALTER TABLE episodes ALTER COLUMN title TYPE TEXT');
        $this->addSql('ALTER TABLE seasons ALTER COLUMN title TYPE TEXT');
        $this->addSql('SET lock_timeout = 0');
    }

    /*
     * Narrowing back would fail on any row that has since been stored in full,
     * so it truncates first. That is lossy, which is what going back to a
     * smaller column means.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("SET lock_timeout = '5s'");
        $this->addSql('UPDATE episodes SET title = left(title, 255) WHERE length(title) > 255');
        $this->addSql('UPDATE seasons SET title = left(title, 255) WHERE length(title) > 255');
        $this->addSql('ALTER TABLE episodes ALTER COLUMN title TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE seasons ALTER COLUMN title TYPE VARCHAR(255)');
        $this->addSql('SET lock_timeout = 0');
    }
}
