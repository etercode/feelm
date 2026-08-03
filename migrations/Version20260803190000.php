<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where our own copy of a work's artwork lives, alongside where TMDB's does.
 *
 * Deliberately new columns rather than a rewrite of `poster` and `backdrop`.
 * Those hold the TMDB CDN URL the crawl found, and they keep holding it: if a
 * mirrored object is lost, mis-uploaded or the bucket goes away, every row can
 * still say where the original came from and the site falls back to it without
 * anybody restoring anything. Rewriting 1.2 million rows in place would have
 * thrown that away for the sake of one column.
 *
 * These store an object *key* — "posters/w500/abc.jpg" — and not a URL. The
 * host is a property of how we serve today, not of the image: putting a CDN in
 * front, or moving region, is then one environment variable instead of an
 * UPDATE across the whole table.
 *
 * Both nullable, because "not mirrored yet" is the normal state for most of the
 * catalog during a run and the ordinary state for anything TMDB has no artwork
 * for. The partial indexes are what the mirror command pages through; they only
 * cover rows still to do, so they shrink to nothing as it finishes rather than
 * growing to 1.2 million entries nobody queries.
 */
final class Version20260803190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mirrored artwork keys to works, keeping the TMDB URLs';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE works
            ADD poster_mirror TEXT DEFAULT NULL,
            ADD backdrop_mirror TEXT DEFAULT NULL');

        $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_poster_todo
            ON works (id)
            WHERE poster_mirror IS NULL AND poster LIKE 'http%'");

        $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_work_backdrop_todo
            ON works (id)
            WHERE backdrop_mirror IS NULL AND backdrop LIKE 'http%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_poster_todo');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_work_backdrop_todo');
        $this->addSql('ALTER TABLE works DROP poster_mirror, DROP backdrop_mirror');
    }
}
