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
        $total = null;
        if ($withTotal) {
            $total = (int) $this->connection()->executeQuery(
                'SELECT COUNT(*) FROM works w '.$this->joins($criteria).' WHERE '.$where,
                $params,
                $types,
            )->fetchOne();
        }

        $ids = $this->ids($criteria, $where, $params, $types, $withTotal ? 0 : 1);
        $hasMore = $withTotal
            ? $criteria->offset() + \count($ids) < $total
            : \count($ids) > $criteria->limit;

        if (!$withTotal) {
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
            'page' => $criteria->page,
            'limit' => $criteria->limit,
            'pages' => null === $total ? null : (int) ceil($total / $criteria->limit),
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
        return $this->fuzzy[$tsquery] ??= !(bool) $this->connection()->executeQuery(
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

        $params['limit'] = $criteria->limit + $extra;
        $params['offset'] = $criteria->offset();
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $this->connection()->executeQuery($sql, $params, $types)->fetchFirstColumn();

        return array_map('intval', $rows);
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
    private function orderExpressions(SearchCriteria $criteria): array
    {
        if ('relevance' === $criteria->sort && $criteria->hasQuery()) {
            return [
                'select' => 'w.popularity AS s_pop,
                    ts_rank_cd(w.search_vector, query.q) AS s_rank,
                    (lower(w.title) = lower(:qtext)) AS s_exact,
                    (lower(w.title) LIKE lower(:qprefix)) AS s_prefix,
                    similarity(w.title, :qtrgm) AS s_sim',
                // Exact title, then "starts with", then text rank, then how
                // close the spelling is, then popularity as the tiebreak.
                'order' => 's_exact DESC, s_prefix DESC, s_rank DESC, s_sim DESC, s_pop DESC NULLS LAST, w.id DESC',
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
    private function tsquery(array $tokens): string
    {
        $last = array_pop($tokens);
        $terms = $tokens;
        $terms[] = $last.':*';

        return implode(' & ', $terms);
    }

    /**
     * @return array<string, int>
     */
    private function typeFacet(SearchCriteria $criteria): array
    {
        [$where, $params, $types] = $this->conditions($criteria);

        $rows = $this->connection()->executeQuery(
            'SELECT w.type, COUNT(*) AS n FROM works w '.$this->joins($criteria).'
             WHERE '.$where.' GROUP BY w.type',
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
        $rows = $this->connection()->executeQuery(
            'SELECT fg.slug, fg.name, COUNT(*) AS n
             FROM works w '.$this->joins($criteria).'
             JOIN work_genre fwg ON fwg.work_id = w.id
             JOIN genres fg ON fg.id = fwg.genre_id
             WHERE '.$where.'
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

        $rows = $this->connection()->executeQuery(
            'SELECT (w.year / 10) * 10 AS decade, COUNT(*) AS n
             FROM works w '.$this->joins($criteria).'
             WHERE '.$where.' AND w.year IS NOT NULL
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
