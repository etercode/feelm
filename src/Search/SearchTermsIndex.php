<?php

namespace App\Search;

use Doctrine\DBAL\Connection;

/**
 * Maintains the lexicon behind "did you mean".
 *
 * The words the catalog knows are kept in one small table with a trigram
 * index, so a correction costs an index lookup. The obvious alternative —
 * splitting every title in the table at query time — reads the whole catalog
 * on every keystroke, which is fine for a seed file and hopeless once the
 * crawler has been running.
 */
final class SearchTermsIndex
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 80;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Rebuilds the lexicon from titles and people. Cheap enough to run at the
     * end of a crawl: one sequential pass, no per-row queries.
     *
     * @return int number of terms known afterwards
     */
    public function rebuild(): int
    {
        $this->connection->executeStatement(
            'CREATE TEMPORARY TABLE tmp_search_terms AS
             SELECT term, COUNT(*)::int AS uses FROM (
                 SELECT unnest(string_to_array(
                     regexp_replace(lower(coalesce(title, \'\') || \' \' || coalesce(original_title, \'\')), \'[^[:alnum:]]+\', \' \', \'g\'),
                     \' \'
                 )) AS term FROM works WHERE deleted_at IS NULL
                 UNION ALL
                 SELECT unnest(string_to_array(
                     regexp_replace(lower(name), \'[^[:alnum:]]+\', \' \', \'g\'),
                     \' \'
                 )) AS term FROM people
                 UNION ALL
                 SELECT lower(slug) AS term FROM genres
             ) words
             WHERE length(term) BETWEEN :min AND :max
             GROUP BY term',
            ['min' => self::MIN_LENGTH, 'max' => self::MAX_LENGTH],
        );

        $this->connection->executeStatement(
            'DELETE FROM search_terms st WHERE NOT EXISTS (
                 SELECT 1 FROM tmp_search_terms t WHERE t.term = st.term
             )',
        );

        $this->connection->executeStatement(
            'INSERT INTO search_terms (term, uses)
             SELECT term, uses FROM tmp_search_terms
             ON CONFLICT (term) DO UPDATE SET uses = EXCLUDED.uses',
        );

        $this->connection->executeStatement('DROP TABLE tmp_search_terms');

        return (int) $this->connection->executeQuery('SELECT COUNT(*) FROM search_terms')->fetchOne();
    }
}
