<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Everything else TMDB has been sending that nothing read.
 *
 * Audited against live /movie and /tv responses rather than from memory. What
 * was being thrown away, and why each one is here:
 *
 *   budget, revenue      a film's money is a fact people came to read
 *   homepage             the official site
 *   spoken_languages     not the same as original_language, which is one code
 *   in_production        whether a series is still running
 *   next_episode_at      the air date of the next episode — this is what an
 *                        "airing next" rail sorts by, so it is a column and an
 *                        index rather than a key inside a json blob
 *   episodes_air         the next and last episode themselves: season, number,
 *                        name, date. A blob because nothing queries inside it
 *
 * Watch providers are deliberately absent. TMDB returns them for 107 countries
 * and the full document is 68-100 kB per title — 81 GB across this catalog, on
 * a server with 71 GB free. Trimmed to streaming names only it is still 2.4 GB,
 * and it is the one field here that goes stale on its own: titles leave
 * services weekly. It belongs on a cached read at detail-page time, not in a
 * column that is wrong a month after it is written.
 *
 * CONCURRENTLY on the index because works is 1.5 GB and serving traffic.
 */
final class Version20260804220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the TMDB detail fields that were being discarded';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE works
            ADD COLUMN IF NOT EXISTS budget BIGINT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS revenue BIGINT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS homepage TEXT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS spoken_languages JSONB DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS in_production BOOLEAN DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS next_episode_at DATE DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS episodes_air JSONB DEFAULT NULL');

        // Partial: the rail only ever asks for episodes still to come, and that
        // is a few thousand rows out of a million.
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_next_episode
            ON works (next_episode_at)
            WHERE deleted_at IS NULL AND next_episode_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_next_episode');
        $this->addSql('ALTER TABLE works
            DROP COLUMN IF EXISTS budget,
            DROP COLUMN IF EXISTS revenue,
            DROP COLUMN IF EXISTS homepage,
            DROP COLUMN IF EXISTS spoken_languages,
            DROP COLUMN IF EXISTS in_production,
            DROP COLUMN IF EXISTS next_episode_at,
            DROP COLUMN IF EXISTS episodes_air');
    }
}
