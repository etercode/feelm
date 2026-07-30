<?php

namespace App\Search;

use App\Entity\Work;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     works: list<Work>,
     *     total: int,
     *     page: int,
     *     limit: int,
     *     pages: int,
     *     matched: bool,
     *     suggestion: array{term: string, total: int}|null,
     * }
     */
    public function search(SearchCriteria $criteria, bool $withSuggestion = true): array
    {
        [$where, $params, $types] = $this->conditions($criteria);

        $total = (int) $this->connection()->executeQuery(
            'SELECT COUNT(DISTINCT w.id) FROM works w '.$this->joins($criteria).' WHERE '.$where,
            $params,
            $types,
        )->fetchOne();

        $ids = $this->ids($criteria, $where, $params, $types);

        // Nothing matched? Offer the closest spelling we know, and say how many
        // rows it would return, so the UI can decide whether to lead with it.
        $suggestion = null;
        if ($withSuggestion && $criteria->hasQuery() && $total < self::SUGGEST_BELOW) {
            $suggestion = $this->suggestion($criteria, $total);
        }

        return [
            'works' => $this->hydrate($ids),
            'total' => $total,
            'page' => $criteria->page,
            'limit' => $criteria->limit,
            'pages' => (int) ceil($total / $criteria->limit),
            'matched' => $total > 0,
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
    private function ids(SearchCriteria $criteria, string $where, array $params, array $types): array
    {
        $order = $this->orderExpressions($criteria);

        $sql = 'SELECT DISTINCT w.id, '.$order['select'].'
                FROM works w '.$this->joins($criteria).'
                WHERE '.$where.'
                ORDER BY '.$order['order'].'
                LIMIT :limit OFFSET :offset';

        $params['limit'] = $criteria->limit;
        $params['offset'] = $criteria->offset();
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $this->connection()->executeQuery($sql, $params, $types)->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /**
     * SELECT and ORDER BY fragments for the requested sort.
     *
     * The query is DISTINCT — the genre and credit joins can each match a work
     * more than once — and Postgres will only order a DISTINCT result by
     * expressions that appear in the select list. So every sort key is selected
     * under an alias and the ORDER BY refers to aliases only.
     *
     * @return array{select: string, order: string}
     */
    private function orderExpressions(SearchCriteria $criteria): array
    {
        $shared = 'w.external_score AS s_score,
                   w.popularity AS s_pop,
                   w.release_date AS s_released,
                   w.added_at AS s_added,
                   lower(w.title) AS s_title,
                   (SELECT r.rating FROM work_ratings r
                    WHERE r.work_id = w.id AND r.source = \'imdb\') AS s_imdb';

        if ('relevance' === $criteria->sort && $criteria->hasQuery()) {
            return [
                'select' => $shared.',
                    ts_rank_cd(w.search_vector, query.q) AS s_rank,
                    (lower(w.title) = lower(:qtext)) AS s_exact,
                    (lower(w.title) LIKE lower(:qprefix)) AS s_prefix,
                    similarity(w.title, :qtrgm) AS s_sim',
                // Exact title, then "starts with", then text rank, then how
                // close the spelling is, then popularity as the tiebreak.
                'order' => 's_exact DESC, s_prefix DESC, s_rank DESC, s_sim DESC, s_pop DESC NULLS LAST, w.id DESC',
            ];
        }

        $orders = [
            'score' => 's_score DESC NULLS LAST',
            // Unrated titles sort last rather than pretending to be zero.
            'imdb' => 's_imdb DESC NULLS LAST',
            'popularity' => 's_pop DESC NULLS LAST',
            'newest' => 's_released DESC NULLS LAST',
            'oldest' => 's_released ASC NULLS LAST',
            'title' => 's_title ASC',
            'added' => 's_added DESC',
            'relevance' => 's_pop DESC NULLS LAST',
        ];

        return [
            'select' => $shared,
            'order' => ($orders[$criteria->sort] ?? $orders['popularity']).', w.id DESC',
        ];
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
        $clauses = ['TRUE'];
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
             */
            $clauses[] = '(w.search_vector @@ query.q OR w.title % :qtrgm)';
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
            'SELECT w.type, COUNT(DISTINCT w.id) AS n FROM works w '.$this->joins($criteria).'
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

        // This one does join genres — it is grouping by them.
        $rows = $this->connection()->executeQuery(
            'SELECT fg.slug, fg.name, COUNT(DISTINCT w.id) AS n
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
            'SELECT (w.year / 10) * 10 AS decade, COUNT(DISTINCT w.id) AS n
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
            ->select('w', 'g', 'r')
            ->from(Work::class, 'w')
            ->leftJoin('w.genres', 'g')
            ->leftJoin('w.ratings', 'r')
            ->where('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

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
