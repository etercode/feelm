<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The crawl queue for series, alongside the one movies already have.
 *
 * A separate table rather than a `kind` column on `tmdb_movie_ids`: the movie
 * queue is 727k rows with three partial indexes tuned for "what is left", and
 * adding a discriminator to it would mean every one of those indexes grows a
 * leading column for the sake of a table that never joins to it. Two tables
 * that each answer one question stay cheaper and easier to read.
 *
 * `skipped_reason` is the difference from the movie queue, and the point of it:
 * TMDB is community-edited, so a good part of the export is somebody's home
 * video with no poster and no episodes. The crawler cannot know that until it
 * has fetched the title, so it records why it threw one away — which is what
 * makes the filter auditable instead of a silent hole in the catalog.
 */
final class Version20260801100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tmdb_series_ids, the crawl queue for TV';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tmdb_series_ids (
    tmdb_id bigint NOT NULL,
    popularity double precision DEFAULT 0 NOT NULL,
    exported_on date NOT NULL,
    crawled_at timestamp(0) without time zone DEFAULT NULL,
    skipped_reason character varying(32) DEFAULT NULL
)');
        $this->addSql('ALTER TABLE ONLY tmdb_series_ids ADD CONSTRAINT tmdb_series_ids_pkey PRIMARY KEY (tmdb_id)');

        // The crawl's own query: what is left, most popular first. Partial, so
        // it shrinks as the crawl runs instead of staying at export size.
        $this->addSql('CREATE INDEX idx_tmdb_series_todo ON tmdb_series_ids USING btree (popularity DESC, tmdb_id) WHERE (crawled_at IS NULL)');
        // For the status page, which counts rejections by reason.
        $this->addSql('CREATE INDEX idx_tmdb_series_skipped ON tmdb_series_ids USING btree (skipped_reason) WHERE (skipped_reason IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tmdb_series_ids');
    }
}
