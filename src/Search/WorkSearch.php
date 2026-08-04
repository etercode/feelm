<?php

namespace App\Search;

use App\Entity\Work;
use App\Service\Catalog\WorkHydrator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The catalog search.
 *
 * Text matching is Postgres full text against the stored, weighted tsvector on
 * works — a title hit outranks an overview hit, and the GIN index means the
 * cost does not grow with the catalog. Trigram similarity is used for two
 * narrow jobs only: rescuing a query that full text found nothing for, and
 * proposing a correction.
 *
 * Filters, counts and facets all run through one WHERE builder, so the number
 * on the page always describes the rows on the page.
 */
final class WorkSearch
{
    /** Below this many results a correction is worth offering. */
    private const SUGGEST_BELOW = 5;

    /**
     * How many matches relevance ranking is allowed to consider.
     *
     * Generous enough that paging deep into a normal search never runs out —
     * seventy a page is forty-two pages — and small enough that the per-row
     * cost of ts_rank_cd stops mattering.
     */
    private const RELEVANCE_POOL = 3000;

    /**
     * Where counting stops and "and more" begins.
     *
     * Above this the exact figure is not worth 1.4 seconds of anybody's search,
     * and the pager only needs to know there is another page.
     */
    private const COUNT_CEILING = 1000;

    /**
     * How many matches a facet counts over: exact for anything narrower,
     * indicative above it.
     *
     * Tied to the count ceiling so the numbers on one page agree with each
     * other. Sampling deeper than we count reads as a mistake — "1,000+
     * results" over a chip claiming 1,376 of them are drama.
     */
    private const FACET_SAMPLE = self::COUNT_CEILING;

    /**
     * Per-query answers from needsFuzzy(), for the life of one request.
     *
     * @var array<string, bool>
     */
    private array $fuzzy = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkHydrator $hydrator,
    ) {
    }

    /**
     * @return array{
     *     works: list<Work>,
     *     total: int|null,
     *     page: int,
     *     limit: int,
     *     pages: int|null,
     *     hasMore: bool,
     *     matched: bool,
     *     suggestion: array{term: string, total: int}|null,
     * }
     */
    public function search(SearchCriteria $criteria, bool $withSuggestion = true, bool $withTotal = true): array
    {
        [$where, $params, $types] = $this->conditions($criteria);

        /*
         * Counting is about half the work of a listing — 189ms against 225ms
         * for the rows themselves, because there is no way to know how many of
         * seven hundred thousand match without looking at all of them.
         *
         * A caller that does not print the number does not have to pay for it.
         * It gets one row more than it asked for instead, which answers the
         * only other question a pager has: is there a page after this one.
         */
        /*
         * Counted up to a ceiling, not exhaustively.
         *
         * There is no way to know how many of seven hundred thousand rows match
         * without visiting all of them, and for a two-letter prefix that is
         * 305,242 of them — 1.4 seconds on the server, spent to print a number
         * nobody reads digit by digit. Stopping at COUNT_CEILING costs the
         * exact figure only for searches too broad to have one worth printing,
         * and totalIsExact says which kind came back so the UI can write
         * "1,000+" rather than claim a precision it does not have.
         */
        $total = null;
        $exact = true;
        if ($withTotal) {
            $total = (int) $this->connection()->executeQuery(
                'SELECT COUNT(*) FROM (
                    SELECT 1 FROM works w '.$this->joins($criteria).' WHERE '.$where.'
                    LIMIT '.self::COUNT_CEILING.'
                 ) capped',
                $params,
                $types,
            )->fetchOne();
            $exact = $total < self::COUNT_CEILING;
        }

        /*
         * One row over the limit whenever the total cannot settle the question.
         * A capped total says "at least a thousand" and nothing about where the
         * last page falls, so at offset 990 the arithmetic below would conclude
         * there is nothing after it — with 305,242 rows still to come.
         */
        $overfetch = $withTotal && $exact ? 0 : 1;
        $ids = $this->ids($criteria, $where, $params, $types, $overfetch);
        $hasMore = 0 === $overfetch
            ? $criteria->offset() + \count($ids) < $total
            : \count($ids) > $criteria->limit;

        if ($overfetch > 0) {
            $ids = \array_slice($ids, 0, $criteria->limit);
        }

        // Nothing matched? Offer the closest spelling we know, and say how many
        // rows it would return, so the UI can decide whether to lead with it.
        $suggestion = null;
        if ($withSuggestion && $criteria->hasQuery() && null !== $total && $total < self::SUGGEST_BELOW) {
            $suggestion = $this->suggestion($criteria, $total);
        }

        return [
            'works' => $this->hydrate($ids),
            'total' => $total,
            // False when the count stopped at the ceiling: the caller has a
            // floor, not a figure, and should print it as one.
            'totalIsExact' => $exact,
            'page' => $criteria->page,
            'limit' => $criteria->limit,
            'pages' => null === $total || !$exact ? null : (int) ceil($total / $criteria->limit),
            'hasMore' => $hasMore,
            'matched' => null === $total ? [] !== $ids : $total > 0,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * Counts for the filter panel. Each group is counted with its own filter
     * lifted, which is what lets you see "Drama 412" while Drama is selected.
     *
     * @return array{types: array<string, int>, genres: list<array{slug: string, name: string, count: int}>, decades: list<array{decade: int, count: int}>}
     */
    public function facets(SearchCriteria $criteria): array
    {
        return [
            'types' => $this->typeFacet($criteria->without('type')),
            'genres' => $this->genreFacet($criteria->without('genre')),
            'decades' => $this->decadeFacet($criteria->without('year')),
        ];
    }

    /**
     * "Did you mean" — the closest known spelling of each word in the query,
     * looked up in the search_terms lexicon through a trigram index.
     *
     * Only offered when it is actually an improvement. Proposing a spelling that
     * returns fewer results than what was typed is worse than saying nothing:
     * searching "blacksmith" and being asked whether you meant "blacksmiths"
     * reads as the search doubting a word you spelled correctly.
     *
     * @return array{term: string, total: int}|null
     */
    public function suggestion(SearchCriteria $criteria, int $currentTotal = 0): ?array
    {
        $corrected = $this->correct((string) $criteria->query);
        if (null === $corrected) {
            return null;
        }

        $probe = $this->search($criteria->withQuery($corrected), withSuggestion: false);
        if ($probe['total'] <= $currentTotal) {
            return null;
        }

        return ['term' => $corrected, 'total' => $probe['total']];
    }

    /** The corrected spelling of a phrase, or null when nothing is close. */
    public function correct(string $query): ?string
    {
        $tokens = $this->tokens($query);
        if ([] === $tokens) {
            return null;
        }

        $changed = false;
        $corrected = [];

        foreach ($tokens as $token) {
            $best = mb_strlen($token) < 3 ? null : $this->closestTerm($token);
            if (null !== $best && $best !== $token) {
                $changed = true;
                $corrected[] = $best;
            } else {
                $corrected[] = $token;
            }
        }

        return $changed ? implode(' ', $corrected) : null;
    }

    /* ------------------------------------------------------------ internals */

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * Whether the fuzzy title match is worth adding to a query.
     *
     * Only when full text finds nothing at all — which is the case the fallback
     * was written for, a misspelling that still has to return something.
     *
     * Deliberately asks about the text alone, ignoring every other filter, so
     * the answer depends only on the words typed. The body of a search and its
     * facet counts are built from separate calls to conditions(); if they could
     * disagree about whether the net was cast, the facets would add up to more
     * than the total on the page.
     *
     * Memoised because one request asks four times over: the count, the row
     * list, and each facet.
     */
    private function needsFuzzy(string $tsquery): bool
    {
        return $this->fuzzy[$tsquery] ??= $this->fullTextFindsNothing($tsquery);
    }

    /**
     * Whether full text can match anything, answered the cheap way first.
     *
     * Asking the GIN index directly is fine when the answer is yes: it finds a
     * row and LIMIT 1 stops. It is terrible when the answer is no, and worst
     * for the prefix term — proving that nothing starts with "vvbblargh" means
     * walking that part of the index to exhaustion. Measured at 2.0s against
     * 876k works, on every misspelling anybody types.
     *
     * search_terms is the vocabulary of the same corpus, one row per distinct
     * word, with a btree on it. A word that is not in there cannot be in any
     * work's search vector, and the query ANDs its terms — so one missing word
     * settles it for the whole query, in about two milliseconds.
     *
     * Only when every word does exist somewhere does the real question — do
     * they occur in the *same* work — get asked, and that is the case where the
     * index has rows to find and answers quickly.
     */
    private function fullTextFindsNothing(string $tsquery): bool
    {
        foreach (explode(' & ', $tsquery) as $term) {
            $prefix = str_ends_with($term, ':*');
            $word = $prefix ? substr($term, 0, -2) : $term;

            if ('' === $word) {
                continue;
            }

            $known = (bool) $this->connection()->executeQuery(
                $prefix
                    ? 'SELECT 1 FROM search_terms WHERE term LIKE :word LIMIT 1'
                    : 'SELECT 1 FROM search_terms WHERE term = :word LIMIT 1',
                ['word' => $prefix ? $word.'%' : $word],
            )->fetchOne();

            if (!$known) {
                return true;
            }
        }

        /*
         * One word, and the catalog knows it: something contains it, because
         * search_terms is built from works. No point asking the index a
         * question its own source has already answered.
         *
         * That matters more than it sounds. The probe below is a LIMIT 1 over
         * a tsquery, and for a word held by one work in eight hundred thousand
         * the planner reads the estimate (2,000 rows), decides a sequential
         * scan will hit one almost immediately, and reads the whole table
         * instead — 805,707 rows and 1.9 seconds to confirm a match it was
         * always going to find. Every rare title did that.
         *
         * A stale vocabulary can only be wrong in the harmless direction: it
         * would claim a match for a word whose last work was deleted since the
         * index was built, and the search returns nothing instead of offering
         * a fuzzy alternative until the next reindex.
         */
        if (!str_contains($tsquery, ' & ')) {
            return false;
        }

        // Several words, each known but perhaps never together. Only the index
        // can say, and here it has rows to find.
        return !(bool) $this->connection()->executeQuery(
            "SELECT 1 FROM works w
             WHERE w.deleted_at IS NULL AND w.search_vector @@ to_tsquery('simple', :tsquery)
             LIMIT 1",
            ['tsquery' => $tsquery],
        )->fetchOne();
    }

    /**
     * One word, corrected — or null when there is nothing to correct.
     *
     * A word the catalog already knows is never a typo, whatever else looks
     * similar to it, which is what the NOT EXISTS guard is for. Without it
     * "blacksmith" gets helpfully rewritten to "blacksmiths".
     *
     * `%` is the trigram operator, so both halves are index lookups rather than
     * a scan over every title.
     */
    private function closestTerm(string $token): ?string
    {
        $row = $this->connection()->executeQuery(
            'SELECT term FROM search_terms
             WHERE term % :token
               AND term <> :token
               AND NOT EXISTS (SELECT 1 FROM search_terms known WHERE known.term = :token)
             ORDER BY similarity(term, :token) DESC, uses DESC
             LIMIT 1',
            ['token' => $token],
        )->fetchOne();

        return false === $row || null === $row ? null : (string) $row;
    }

    /**
     * @param array<string, mixed>            $params
     * @param array<string, ParameterType|ArrayParameterType|int> $types
     *
     * @return list<int>
     */
    private function ids(SearchCriteria $criteria, string $where, array $params, array $types, int $extra = 0): array
    {
        $order = $this->orderExpressions($criteria);

        /*
         * Not DISTINCT. The only thing joined is the lateral carrying the
         * parsed tsquery, which is a single row — every genre, rating, person
         * and credit filter is an EXISTS or a scalar subquery in the WHERE, so
         * no row can appear twice. Deduping anyway cost the sort: with it,
         * Postgres spilled 12 MB to disk; without, it is a 26 kB top-N heap.
         */
        $sql = 'SELECT w.id, '.$order['select'].'
                FROM works w '.$this->joins($criteria).'
                WHERE '.$where.'
                ORDER BY '.$order['order'].'
                LIMIT :limit OFFSET :offset';

        /*
         * Relevance is ranked over a bounded pool rather than over everything
         * that matched.
         *
         * ts_rank_cd has to read each row's search_vector off the heap, which
         * the GIN index cannot supply, so its cost is per matching row and not
         * per row returned. That is invisible for "matrix" — 149 matches — and
         * ruinous for a common word: "the" matches 629,461 titles, and ranking
         * all of them took 6.4 seconds to hand back thirty.
         *
         * So the pool is cut to the most popular POOL matches first, by a plain
         * top-N sort on a column already in hand, and the expensive ordering
         * runs only over those: 830ms for the same query.
         *
         * The results also got better, which is worth saying because it sounds
         * like the opposite trade. Ranking everything, "love" returned six
         * films called Love with popularity between 0.1 and 2.2 — exact title
         * matches nobody was looking for. Bounding the pool first keeps the
         * exact-title rule and applies it to the films people have heard of.
         *
         * Anything matching fewer than POOL rows is unaffected: the pool is
         * then the whole match set, and the ordering is exactly what it was.
         */
        $params['limit'] = $criteria->limit + $extra;
        $params['offset'] = $criteria->offset();
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        if (!$this->ranksByRelevance($criteria)) {
            $rows = $this->connection()->executeQuery($sql, $params, $types)->fetchFirstColumn();

            return array_map('intval', $rows);
        }

        /*
         * The pool has to cover the page being asked for, or paging past it
         * returns nothing at all — which is not "the end of the results", it
         * is a blank page 44 sitting after a full page 43.
         */
        $pool = max(self::RELEVANCE_POOL, $criteria->offset() + $criteria->limit + $extra);

        /*
         * Tried against the popular-only index first.
         *
         * Finding the most popular POOL matches means visiting every match:
         * "ma" matches 305,242 rows, 1.4 seconds before ranking begins, and
         * the overlay pays it from the second character typed. But a work with
         * no popularity cannot reach the top of a pool sorted by popularity
         * while a popular one is still waiting — so if the partial index
         * (popularity >= 1) fills the pool by itself, its answer is the same
         * answer, found in a quarter of the index.
         *
         * "If" is checked, not assumed, and the check is whether the pool
         * filled — not whether the page did. Those differ: 500 popular matches
         * fill a page of thirty while leaving 2,500 places in the pool that
         * unpopular rows were entitled to, and one of them may be the exact
         * title somebody typed. A short pool means the floor changed the
         * answer, so the query is repeated without it.
         *
         * That second pass only happens for searches with fewer popular
         * matches than the pool holds, which are cheap by definition.
         */
        [$ids, $filled] = $this->pooled($criteria, $where, $params, $types, $order, $pool, true);
        if ($filled >= $pool) {
            return $ids;
        }

        return $this->pooled($criteria, $where, $params, $types, $order, $pool, false)[0];
    }

    /**
     * @param array<string, mixed>                               $params
     * @param array<string, ParameterType|ArrayParameterType|int> $types
     * @param array{select: string, order: string}               $order
     *
     * @return array{0: list<int>, 1: int} the page, and how many rows the pool held
     */
    private function pooled(
        SearchCriteria $criteria,
        string $where,
        array $params,
        array $types,
        array $order,
        int $pool,
        bool $popularOnly,
    ): array {
        // Matches the partial index's predicate exactly, or Postgres will not
        // use it.
        $floor = $popularOnly ? ' AND w.popularity >= 1' : '';

        /*
         * count(*) OVER () is the pool's size, not the page's: window functions
         * are evaluated before LIMIT, so it counts every row the pool held even
         * though thirty come back.
         */
        $sql = 'WITH pool AS (
                    SELECT w.id, w.title, w.popularity, w.search_vector
                    FROM works w '.$this->joins($criteria).'
                    WHERE '.$where.$floor.'
                    ORDER BY w.popularity DESC NULLS LAST, w.id DESC
                    LIMIT :pool
                )
                SELECT w.id, COUNT(*) OVER () AS pool_size, '.$order['select'].'
                FROM pool w '.$this->joins($criteria).'
                ORDER BY '.$order['order'].'
                LIMIT :limit OFFSET :offset';

        $params['pool'] = $pool;
        $types['pool'] = ParameterType::INTEGER;

        $rows = $this->connection()->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return [
            array_map(static fn (array $row) => (int) $row['id'], $rows),
            (int) ($rows[0]['pool_size'] ?? 0),
        ];
    }

    /**
     * SELECT and ORDER BY fragments for the requested sort.
     *
     * Only the key being sorted on is selected. Every key used to be, which
     * cost more than it looks: the row list was SELECT DISTINCT, and Postgres
     * dedupes by sorting on every selected column — so six keys meant sorting
     * 412k rows on six columns and spilling twelve megabytes to disk, to
     * return twenty-four ids ordered by one of them. It also evaluated the
     * IMDb subquery on every request that was not sorted by IMDb.
     *
     * @return array{select: string, order: string}
     */
    /**
     * Whether this search pays ts_rank_cd, and so needs the bounded pool.
     *
     * Any other sort orders by a plain column and never touches the vector, so
     * bounding it there would cut results for nothing.
     */
    private function ranksByRelevance(SearchCriteria $criteria): bool
    {
        return 'relevance' === $criteria->sort && $criteria->hasQuery();
    }

    private function orderExpressions(SearchCriteria $criteria): array
    {
        if ($this->ranksByRelevance($criteria)) {
            /*
             * Titles are compared with a leading article removed, on both
             * sides. Nobody types "the" — they search "odyssey", "matrix",
             * "godfather" — and without this those three queries returned ten
             * obscure films titled exactly Odyssey, Matrix and Godfather while
             * the ones anybody meant did not appear at all. An exact title was
             * the first sort key and absolute, so a film with 0.5 popularity
             * beat The Odyssey with 1,154 for want of a word.
             *
             * Popularity then decides inside the exact group, which is the
             * other half of the fix: there are a dozen films called Odyssey and
             * the question is which one somebody typing it means. The CASE
             * confines that to exact matches — for everything else it is NULL
             * for every row, so it cannot discriminate, and text rank still
             * orders them.
             */
            /*
             * One score, rather than exactness first and popularity as an
             * afterthought.
             *
             * Strict ordering could not express what people mean. "pulp" has
             * to reach Pulp Fiction past five films called exactly Pulp, so
             * exactness cannot be absolute — but "up" has to reach Up past
             * Uppdrag granskning, and "toy story" has to reach the 1995 one
             * past Toy Story 5, so it cannot be worthless either. Nothing
             * ordered strictly satisfies both; a title match has to be worth
             * some amount of popularity rather than all of it or none.
             *
             * Popularity enters as a logarithm because it spans four orders of
             * magnitude here — 0.7 to 1,154 — and the difference between 40 and
             * 300 should not swamp a title match the way the raw numbers would.
             *
             * The weights are fitted to the cases above, not guessed. Writing
             * out what each demands leaves the gap between exact and
             * starts-with between 2.02 and 2.46 — Her against Her Private Hell
             * at one end, Pulp against Pulp Fiction at the other — so it is
             * 2.2, which is worth about nine times the popularity.
             *
             * The 20 is not fitted; it is larger than ln of the most popular
             * thing in the catalog can ever be, so a title match always
             * outranks a row that merely mentions the words. Without it,
             * searching "alien" led with Disclosure Day — popularity 439, the
             * word somewhere in its text — above a film actually called Alien.
             * Ranking a passing mention over a title is never what a title
             * search meant.
             */
            return [
                'select' => "w.popularity AS s_pop,
                    ts_rank_cd(w.search_vector, query.q) AS s_rank,
                    (regexp_replace(lower(w.title), '^(the|a|an)\\s+', '') = :qbare) AS s_exact,
                    (regexp_replace(lower(w.title), '^(the|a|an)\\s+', '') LIKE :qbareprefix) AS s_prefix,
                    (CASE WHEN regexp_replace(lower(w.title), '^(the|a|an)\\s+', '') LIKE :qbareprefix THEN 20 ELSE 0 END
                     + CASE WHEN regexp_replace(lower(w.title), '^(the|a|an)\\s+', '') = :qbare THEN 2.2 ELSE 0 END
                     + ln(1 + GREATEST(COALESCE(w.popularity, 0), 0))) AS s_score,
                    similarity(w.title, :qtrgm) AS s_sim",
                // Text rank and spelling distance settle rows the score ties,
                // which is mostly rows with no title match at all.
                'order' => 's_score DESC, s_rank DESC, s_sim DESC, s_pop DESC NULLS LAST, w.id DESC',
            ];
        }

        $keys = [
            'score' => ['w.external_score AS s_score', 's_score DESC NULLS LAST'],
            // Unrated titles sort last rather than pretending to be zero.
            'imdb' => [
                "(SELECT r.rating FROM work_ratings r
                  WHERE r.work_id = w.id AND r.source = 'imdb') AS s_imdb",
                's_imdb DESC NULLS LAST',
            ],
            'popularity' => ['w.popularity AS s_pop', 's_pop DESC NULLS LAST'],
            'newest' => ['w.release_date AS s_released', 's_released DESC NULLS LAST'],
            'oldest' => ['w.release_date AS s_released', 's_released ASC NULLS LAST'],
            'title' => ['lower(w.title) AS s_title', 's_title ASC'],
            'added' => ['w.added_at AS s_added', 's_added DESC'],
        ];

        // Relevance without a query to rank against is just popularity.
        [$select, $order] = $keys[$criteria->sort] ?? $keys['popularity'];

        return ['select' => $select, 'order' => $order.', w.id DESC'];
    }

    /**
     * The only join is the lateral carrying the parsed tsquery, so WHERE and
     * ORDER BY can both see it. Genre and credit filters are EXISTS subqueries
     * rather than joins: a join multiplies a work by its matching genres, which
     * DISTINCT then has to undo — and which makes "match all" impossible to
     * express.
     */
    private function joins(SearchCriteria $criteria): string
    {
        return $criteria->hasQuery()
            ? " CROSS JOIN LATERAL (SELECT to_tsquery('simple', :tsquery) AS q) query"
            : '';
    }

    /**
     * The shared WHERE. Every count, list and facet goes through here, so they
     * cannot disagree with each other.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType|ArrayParameterType|int>}
     */
    private function conditions(SearchCriteria $criteria): array
    {
        /*
         * Hidden works are hidden here, once, for everything: the count, the
         * id list, all three facets and the spelling suggestion every build
         * their WHERE from this method, so none of them can disagree with
         * another about what the catalog contains.
         */
        $clauses = ['w.deleted_at IS NULL'];
        $params = [];
        $types = [];

        if ($criteria->hasQuery()) {
            $query = (string) $criteria->query;
            $tokens = $this->tokens($query);

            if ([] === $tokens) {
                // Punctuation only — match nothing rather than everything.
                return ['FALSE', [], []];
            }

            $params['tsquery'] = $this->tsquery($tokens);
            $params['qtext'] = $query;
            $params['qprefix'] = $query.'%';
            $params['qtrgm'] = mb_strtolower($query);
            // The same shape the title is reduced to before comparing — see
            // orderExpressions(). Done once here rather than per row.
            $params['qbare'] = $bare = $this->withoutArticle(mb_strtolower(trim($query)));
            $params['qbareprefix'] = $bare.'%';

            /*
             * Full text first; trigram similarity on the title is the safety
             * net for a misspelling that still has to return something. Both
             * are index-backed.
             *
             * The net is only cast when full text came back empty. Held open
             * always, it was the most expensive thing in a search: the trigram
             * index is lossy, so every loose match has to be fetched from the
             * heap and re-tested, and for "star" that meant reading 20,169
             * candidate rows to keep 3,135 of them. It added 204 results to
             * 25,662 and three quarters again to the time.
             */
            $clauses[] = $this->needsFuzzy($params['tsquery'])
                ? '(w.search_vector @@ query.q OR w.title % :qtrgm)'
                : 'w.search_vector @@ query.q';
        }

        if ([] !== $criteria->types) {
            $clauses[] = 'w.type IN (:types)';
            $params['types'] = $criteria->types;
            $types['types'] = ArrayParameterType::STRING;
        }

        if ([] !== $criteria->genres) {
            $params['genres'] = $criteria->genres;
            $types['genres'] = ArrayParameterType::STRING;

            if ('all' === $criteria->genreMode) {
                // Has to carry every selected genre, not just one of them.
                $clauses[] = '(
                    SELECT COUNT(DISTINCT g.slug)
                    FROM work_genre wg
                    JOIN genres g ON g.id = wg.genre_id
                    WHERE wg.work_id = w.id AND g.slug IN (:genres)
                ) = :genreCount';
                $params['genreCount'] = \count($criteria->genres);
            } else {
                $clauses[] = 'EXISTS (
                    SELECT 1 FROM work_genre wg
                    JOIN genres g ON g.id = wg.genre_id
                    WHERE wg.work_id = w.id AND g.slug IN (:genres)
                )';
            }
        }

        if (null !== $criteria->yearFrom) {
            $clauses[] = 'w.year >= :yearFrom';
            $params['yearFrom'] = $criteria->yearFrom;
        }

        if (null !== $criteria->yearTo) {
            $clauses[] = 'w.year <= :yearTo';
            $params['yearTo'] = $criteria->yearTo;
        }

        if (null !== $criteria->scoreMin) {
            $clauses[] = 'w.external_score >= :scoreMin';
            $params['scoreMin'] = $criteria->scoreMin;
        }

        if (null !== $criteria->scoreMax) {
            $clauses[] = 'w.external_score <= :scoreMax';
            $params['scoreMax'] = $criteria->scoreMax;
        }

        if (null !== $criteria->runtimeMin) {
            $clauses[] = 'w.runtime_minutes >= :runtimeMin';
            $params['runtimeMin'] = $criteria->runtimeMin;
        }

        if (null !== $criteria->runtimeMax) {
            $clauses[] = 'w.runtime_minutes <= :runtimeMax';
            $params['runtimeMax'] = $criteria->runtimeMax;
        }

        // Ratings live in work_ratings, one row per source.
        if (null !== $criteria->imdbMin || null !== $criteria->votesMin) {
            $rating = ['r.work_id = w.id', "r.source = 'imdb'"];
            if (null !== $criteria->imdbMin) {
                $rating[] = 'r.rating >= :imdbMin';
                $params['imdbMin'] = $criteria->imdbMin;
            }
            if (null !== $criteria->votesMin) {
                $rating[] = 'r.votes >= :votesMin';
                $params['votesMin'] = $criteria->votesMin;
            }
            $clauses[] = 'EXISTS (SELECT 1 FROM work_ratings r WHERE '.implode(' AND ', $rating).')';
        }

        if ([] !== $criteria->certifications) {
            $clauses[] = 'w.certification IN (:certifications)';
            $params['certifications'] = $criteria->certifications;
            $types['certifications'] = ArrayParameterType::STRING;
        }

        if ([] !== $criteria->languages) {
            $clauses[] = 'w.original_language IN (:languages)';
            $params['languages'] = $criteria->languages;
            $types['languages'] = ArrayParameterType::STRING;
        }

        if (null !== $criteria->person) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM credits c
                JOIN people p ON p.id = c.person_id
                WHERE c.work_id = w.id AND (p.slug = :person OR p.name ILIKE :personLike)
            )';
            $params['person'] = $criteria->person;
            $params['personLike'] = '%'.$criteria->person.'%';
        }

        // Upcoming is a date comparison, never a stored flag.
        if ('released' === $criteria->releaseState) {
            $clauses[] = '(w.release_date IS NULL OR w.release_date <= CURRENT_DATE)';
        } elseif ('upcoming' === $criteria->releaseState) {
            $clauses[] = 'w.release_date > CURRENT_DATE';
        }

        return [implode(' AND ', $clauses), $params, $types];
    }

    /**
     * Words, lowercased, punctuation dropped. Everything downstream — tsquery,
     * corrections — works from this list.
     *
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $normalised = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($query)) ?? '';

        return array_values(array_filter(explode(' ', $normalised), static fn (string $token) => '' !== $token));
    }

    /**
     * Tokens as a tsquery, with the last one treated as a prefix so results
     * appear while someone is still typing the final word.
     *
     * @param list<string> $tokens
     */
    /**
     * A title or a query with its leading article dropped.
     *
     * Only leading, and only when something follows it: "The Thing" reduces to
     * "thing", but a film actually called "The" keeps its name.
     */
    private function withoutArticle(string $text): string
    {
        return preg_replace('/^(the|a|an)\s+/u', '', $text) ?? $text;
    }

    private function tsquery(array $tokens): string
    {
        $last = array_pop($tokens);
        $terms = $tokens;
        $terms[] = $last.':*';

        return implode(' & ', $terms);
    }

    /**
     * The matching rows a facet counts over, capped.
     *
     * Facets were the most expensive thing left in a broad search: three
     * GROUP BYs, each visiting every match. For "the" that is 629,461 rows
     * counted three times over — five of the seven seconds the search page
     * took, to put a number beside a chip.
     *
     * They count over the most popular FACET_SAMPLE matches instead. Anything
     * narrower than that is counted in full and the numbers are exact; broader
     * and they describe the part of the result anybody is going to page
     * through, which is what the chips are for — narrowing a search, not
     * measuring the catalog.
     *
     * @param array<string, mixed> $params
     */
    private function sampleSql(SearchCriteria $criteria, string $where, array &$params): string
    {
        $params['sample'] = self::FACET_SAMPLE;

        /*
         * No ORDER BY, and that is the whole optimisation.
         *
         * Ordering the sample by popularity means finding the most popular
         * thousand, which means visiting all 629,461 matches first — the LIMIT
         * saves the aggregation and none of the scan. Measured on the server:
         * 1,415ms ordered against 25ms unordered, because unordered lets the
         * index scan stop as soon as it has a thousand rows.
         *
         * Unordered is also the better sample for this purpose. A popularity-
         * ranked thousand is skewed towards blockbusters, and these counts are
         * meant to describe the result as a whole — how much of it is drama,
         * how much is television — so an arbitrary slice represents it more
         * honestly than the top of it does.
         */
        return 'SELECT w.id, w.type, w.year
                FROM works w '.$this->joins($criteria).'
                WHERE '.$where.'
                LIMIT :sample';
    }

    /**
     * @return array<string, int>
     */
    private function typeFacet(SearchCriteria $criteria): array
    {
        [$where, $params, $types] = $this->conditions($criteria);
        $sample = $this->sampleSql($criteria, $where, $params);
        $types['sample'] = ParameterType::INTEGER;

        $rows = $this->connection()->executeQuery(
            'SELECT w.type, COUNT(*) AS n FROM ('.$sample.') w GROUP BY w.type',
            $params,
            $types,
        )->fetchAllAssociative();

        $counts = array_fill_keys(Work::TYPES, 0);
        foreach ($rows as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @return list<array{slug: string, name: string, count: int}>
     */
    private function genreFacet(SearchCriteria $criteria): array
    {
        [$where, $params, $types] = $this->conditions($criteria);

        /*
         * This one does join genres — it is grouping by them — but it still
         * counts plainly. work_genre is keyed on (work_id, genre_id), so a
         * work appears at most once per genre and no group can see it twice:
         * 575,109 rows, 575,109 distinct pairs. COUNT(DISTINCT) made Postgres
         * sort every one of them by (slug, work_id) to prove what the primary
         * key already guarantees.
         */
        $sample = $this->sampleSql($criteria, $where, $params);
        $types['sample'] = ParameterType::INTEGER;

        $rows = $this->connection()->executeQuery(
            'SELECT fg.slug, fg.name, COUNT(*) AS n
             FROM ('.$sample.') w
             JOIN work_genre fwg ON fwg.work_id = w.id
             JOIN genres fg ON fg.id = fwg.genre_id
             GROUP BY fg.slug, fg.name
             ORDER BY n DESC, fg.name ASC
             LIMIT 24',
            $params,
            $types,
        )->fetchAllAssociative();

        return array_map(static fn (array $row) => [
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'count' => (int) $row['n'],
        ], $rows);
    }

    /**
     * @return list<array{decade: int, count: int}>
     */
    private function decadeFacet(SearchCriteria $criteria): array
    {
        [$where, $params, $types] = $this->conditions($criteria);

        $sample = $this->sampleSql($criteria, $where, $params);
        $types['sample'] = ParameterType::INTEGER;

        $rows = $this->connection()->executeQuery(
            'SELECT (w.year / 10) * 10 AS decade, COUNT(*) AS n
             FROM ('.$sample.') w
             WHERE w.year IS NOT NULL
             GROUP BY decade
             ORDER BY decade DESC',
            $params,
            $types,
        )->fetchAllAssociative();

        return array_map(static fn (array $row) => [
            'decade' => (int) $row['decade'],
            'count' => (int) $row['n'],
        ], $rows);
    }

    /**
     * Ids back to entities, in the order the search returned them.
     *
     * @param list<int> $ids
     *
     * @return list<Work>
     */
    private function hydrate(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Work> $works */
        $works = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(Work::class, 'w')
            ->where('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        /*
         * Only what the list presenter reads — see listItem(). That is the
         * score and nothing else for a film or a series: genres are served by
         * the facet and filter endpoints rather than repeated on every row,
         * and the external ids only ever built a link a card does not draw.
         *
         * Games and books are the exception. Their fact line names a developer
         * or an author, which are credits, so a page of them needs the join
         * the other two types do not — and needs it in one query rather than
         * per row.
         */
        $only = [WorkHydrator::RATINGS];
        foreach ($works as $work) {
            if (\in_array($work->getType(), ['game', 'book'], true)) {
                $only[] = WorkHydrator::CREDITS;
                break;
            }
        }

        $this->hydrator->preloadIds($ids, $only);

        $byId = [];
        foreach ($works as $work) {
            $byId[(int) $work->getId()] = $work;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
