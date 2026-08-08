<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes that make the filter panel's counts exact instead of sampled.
 *
 * ---- written by hand, like the ones before it -------------------------------
 *
 * doctrine:migrations:diff cannot see partial indexes and proposes to drop
 * them. Only the additions are here.
 *
 * ---- what these are for -----------------------------------------------------
 *
 * The panel used to count over a thousand-row sample, because counting every
 * match cost more than the number was worth: "how many of these are in
 * Japanese" meant reading every matching row's language out of the heap.
 *
 * All three indexes exist to keep that read out of the heap. Measured against
 * the local catalogue of 876,755 works, per query:
 *
 *   every language, all movies      300ms -> 22ms   (type, original_language)
 *   every language, one genre       765ms -> 75ms   the covering index
 *   every decade, all movies        300ms -> 21ms   (type, year)
 *
 * The covering index is the one that matters most and the one that looks
 * strangest. A genre filter arrives as a join against work_genre, so the
 * planner has a list of work ids and needs one column back for each; without
 * `INCLUDE` that is a heap fetch per matching row, and with it the whole
 * aggregate is an index-only scan.
 *
 * All three are partial on `deleted_at IS NULL` because every read path in the
 * application already carries that condition — see WorkSearch::conditions() —
 * and because hidden works are a small and shrinking part of the table.
 *
 * ---- why not CONCURRENTLY ---------------------------------------------------
 *
 * Doctrine runs each migration inside a transaction, and CREATE INDEX
 * CONCURRENTLY cannot run in one. A plain CREATE INDEX takes a SHARE lock:
 * reads — which is to say the entire site — carry on, and only writes wait.
 * The writer is the nightly crawl, which is not running during a deploy.
 */
final class Version20260808170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial and covering indexes so the search facets can count exactly.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_works_lang_facet
            ON works (type, original_language) WHERE deleted_at IS NULL');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_works_year_facet
            ON works (type, year) WHERE deleted_at IS NULL');

        /*
         * Ordered by id because that is what a join from work_genre or
         * work_tag arrives with, and the three payload columns are the ones
         * the facets group by.
         */
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_works_facet_cover
            ON works (id) INCLUDE (type, original_language, year) WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_works_lang_facet');
        $this->addSql('DROP INDEX IF EXISTS idx_works_year_facet');
        $this->addSql('DROP INDEX IF EXISTS idx_works_facet_cover');
    }
}
