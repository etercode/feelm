<?php

namespace App\Search;

use App\Entity\Work;
use Symfony\Component\HttpFoundation\Request;

/**
 * Everything the advanced search understands, parsed once from the request so
 * the query builder never touches HTTP input.
 */
final class SearchCriteria
{
    public const SORTS = ['relevance', 'score', 'imdb', 'popularity', 'newest', 'oldest', 'title', 'added'];
    public const RELEASE_STATES = ['any', 'released', 'upcoming'];
    public const GENRE_MODES = ['any', 'all'];

    /** Most rows one request may ask for. */
    public const MAX_LIMIT = 100;

    /**
     * @param list<string> $types
     * @param list<string> $genres         genre slugs
     * @param list<string> $certifications
     * @param list<string> $languages
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $types = [],
        public readonly array $genres = [],
        public readonly string $genreMode = 'any',
        public readonly ?int $yearFrom = null,
        public readonly ?int $yearTo = null,
        public readonly ?float $scoreMin = null,
        public readonly ?float $scoreMax = null,
        public readonly ?int $runtimeMin = null,
        public readonly ?int $runtimeMax = null,
        /**
         * IMDb rating in IMDb's own units, 0-10, as a range open at either end.
         *
         * Both bounds, because "worse than 5" is as real a question as "better
         * than 8" — looking for the notoriously bad is a way people browse, and
         * a single floor could not express it. Either may be given alone.
         */
        public readonly ?float $imdbMin = null,
        public readonly ?float $imdbMax = null,
        public readonly ?int $votesMin = null,
        public readonly array $certifications = [],
        public readonly array $languages = [],
        /**
         * Tags, all three kinds, all matched the same way: work_tag is indexed
         * (kind, value, work_id), so "which works carry this" is answered from
         * the index without touching the table. Uppercase ISO codes for
         * countries — 'TR', not 'Turkey' — because that is what TMDB gives and
         * what the index holds.
         */
        public readonly array $countries = [],
        public readonly array $keywords = [],
        public readonly array $companies = [],
        public readonly ?string $person = null,
        public readonly string $releaseState = 'any',
        public readonly string $sort = 'relevance',
        public readonly int $page = 1,
        public readonly int $limit = 24,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = trim($request->query->getString('q'));
        $sort = $request->query->getString('sort') ?: ($query !== '' ? 'relevance' : 'popularity');

        return new self(
            query: '' !== $query ? $query : null,
            types: self::list($request, 'type', Work::TYPES),
            genres: self::list($request, 'genre'),
            genreMode: self::oneOf($request->query->getString('genreMode'), self::GENRE_MODES, 'any'),
            yearFrom: self::intOrNull($request, 'yearFrom'),
            yearTo: self::intOrNull($request, 'yearTo'),
            scoreMin: self::floatOrNull($request, 'scoreMin'),
            scoreMax: self::floatOrNull($request, 'scoreMax'),
            runtimeMin: self::intOrNull($request, 'runtimeMin'),
            runtimeMax: self::intOrNull($request, 'runtimeMax'),
            imdbMin: self::floatOrNull($request, 'imdbMin'),
            imdbMax: self::floatOrNull($request, 'imdbMax'),
            votesMin: self::intOrNull($request, 'votesMin'),
            certifications: self::list($request, 'certification'),
            languages: self::list($request, 'language'),
            // Uppercased because the index holds ISO codes and a query string
            // saying ?country=tr should still find them.
            countries: array_map(strtoupper(...), self::list($request, 'country')),
            keywords: self::list($request, 'keyword'),
            companies: self::list($request, 'company'),
            person: $request->query->getString('person') ?: null,
            releaseState: self::oneOf($request->query->getString('release'), self::RELEASE_STATES, 'any'),
            sort: self::oneOf($sort, self::SORTS, 'relevance'),
            page: max(1, $request->query->getInt('page', 1)),
            /*
             * 100, up from 60. The browse pages ask for 70 — ten rows of seven
             * at the widest breakpoint — and a cap below what a caller asks for
             * does not fail, it quietly returns fewer, which is the sort of
             * thing you chase in the browser for an hour.
             *
             * It stays capped because the limit is also the page size for
             * anonymous callers, and the listing hydrates every row it returns.
             */
            limit: min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 24))),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    public function hasQuery(): bool
    {
        return null !== $this->query && '' !== $this->query;
    }

    /** Any filter beyond the free-text query — used to decide whether to show "clear all". */
    public function hasFilters(): bool
    {
        return [] !== $this->types
            || [] !== $this->genres
            || [] !== $this->certifications
            || [] !== $this->languages
            || null !== $this->yearFrom
            || null !== $this->yearTo
            || null !== $this->scoreMin
            || null !== $this->scoreMax
            || null !== $this->runtimeMin
            || null !== $this->runtimeMax
            || null !== $this->imdbMin
            || null !== $this->imdbMax
            || null !== $this->votesMin
            || null !== $this->person
            || 'any' !== $this->releaseState;
    }

    /** Same criteria with one filter group emptied — how facet counts are built. */
    public function without(string $group): self
    {
        return new self(
            query: $this->query,
            types: 'type' === $group ? [] : $this->types,
            genres: 'genre' === $group ? [] : $this->genres,
            genreMode: $this->genreMode,
            yearFrom: 'year' === $group ? null : $this->yearFrom,
            yearTo: 'year' === $group ? null : $this->yearTo,
            scoreMin: $this->scoreMin,
            scoreMax: $this->scoreMax,
            runtimeMin: $this->runtimeMin,
            runtimeMax: $this->runtimeMax,
            imdbMin: $this->imdbMin,
            imdbMax: $this->imdbMax,
            votesMin: $this->votesMin,
            certifications: 'certification' === $group ? [] : $this->certifications,
            languages: 'language' === $group ? [] : $this->languages,
            countries: 'country' === $group ? [] : $this->countries,
            keywords: 'keyword' === $group ? [] : $this->keywords,
            companies: 'company' === $group ? [] : $this->companies,
            person: $this->person,
            releaseState: $this->releaseState,
            sort: $this->sort,
            page: 1,
            limit: $this->limit,
        );
    }

    /** The same search, with the query text swapped — used for "did you mean". */
    public function withQuery(?string $query): self
    {
        return new self(
            query: $query,
            types: $this->types,
            genres: $this->genres,
            genreMode: $this->genreMode,
            yearFrom: $this->yearFrom,
            yearTo: $this->yearTo,
            scoreMin: $this->scoreMin,
            scoreMax: $this->scoreMax,
            runtimeMin: $this->runtimeMin,
            runtimeMax: $this->runtimeMax,
            imdbMin: $this->imdbMin,
            imdbMax: $this->imdbMax,
            votesMin: $this->votesMin,
            certifications: $this->certifications,
            languages: $this->languages,
            person: $this->person,
            releaseState: $this->releaseState,
            sort: $this->sort,
            page: 1,
            limit: $this->limit,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'q' => $this->query,
            'type' => $this->types,
            'genre' => $this->genres,
            'genreMode' => $this->genreMode,
            'yearFrom' => $this->yearFrom,
            'yearTo' => $this->yearTo,
            'scoreMin' => $this->scoreMin,
            'scoreMax' => $this->scoreMax,
            'runtimeMin' => $this->runtimeMin,
            'runtimeMax' => $this->runtimeMax,
            'imdbMin' => $this->imdbMin,
            'imdbMax' => $this->imdbMax,
            'votesMin' => $this->votesMin,
            'certification' => $this->certifications,
            'language' => $this->languages,
            'person' => $this->person,
            'release' => $this->releaseState,
            'sort' => $this->sort,
            'page' => $this->page,
            'limit' => $this->limit,
        ];
    }

    /**
     * Reads a multi-value filter, accepting every spelling a client might use:
     * `type=movie&type=book`, `type[]=movie&type[]=book`, `type=movie,book`.
     *
     * The first form needs the raw query string. PHP folds repeated scalar keys
     * into the last one, so `$request->query` alone would report only "book" —
     * which is exactly how checking a second type came to return nothing.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function list(Request $request, string $key, array $allowed = []): array
    {
        $raw = $request->query->all()[$key] ?? null;

        $values = [];
        foreach (\is_array($raw) ? $raw : [$raw] as $entry) {
            if (null === $entry || \is_array($entry)) {
                continue;
            }
            foreach (explode(',', (string) $entry) as $part) {
                $values[] = $part;
            }
        }

        foreach (self::repeated($request, $key) as $entry) {
            foreach (explode(',', $entry) as $part) {
                $values[] = $part;
            }
        }

        $values = array_values(array_filter(
            array_map(static fn (string $value) => trim($value), $values),
            static fn (string $value) => '' !== $value,
        ));

        if ([] !== $allowed) {
            $values = array_values(array_intersect($values, $allowed));
        }

        return array_values(array_unique($values));
    }

    /**
     * Every occurrence of `key=` / `key[]=` in the raw query string.
     *
     * @return list<string>
     */
    private static function repeated(Request $request, string $key): array
    {
        $queryString = (string) $request->server->get('QUERY_STRING', '');
        if ('' === $queryString) {
            return [];
        }

        $values = [];
        foreach (explode('&', $queryString) as $pair) {
            if ('' === $pair) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            if ($name === $key || $name === $key.'[]') {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $value, array $allowed, string $fallback): string
    {
        return \in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function intOrNull(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);

        return null === $raw || '' === $raw ? null : (int) $raw;
    }

    private static function floatOrNull(Request $request, string $key): ?float
    {
        $raw = $request->query->get($key);

        return null === $raw || '' === $raw ? null : (float) $raw;
    }
}
