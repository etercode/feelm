<?php

namespace App\Service\Tmdb;

/** Maps TMDB API payloads into the catalog item shape used by app:catalog:seed. */
final class TmdbItemMapper
{
    private const IMG = 'https://image.tmdb.org/t/p';
    private const SITE = 'https://www.themoviedb.org';

    /**
     * @param array<string, string> $slugs type:slug → item id
     *
     * @return array<string, mixed>
     */
    public function mapMovie(array $detail, array &$slugs): array
    {
        $tmdbId = (int) $detail['id'];
        $releaseDate = isset($detail['release_date']) && \is_string($detail['release_date']) && '' !== $detail['release_date']
            ? $detail['release_date']
            : null;
        $year = $releaseDate ? (int) substr($releaseDate, 0, 4) : null;
        $title = (string) ($detail['title'] ?? 'Untitled');

        $collection = null;
        if (isset($detail['belongs_to_collection']) && \is_array($detail['belongs_to_collection'])) {
            $collection = [
                'name' => $detail['belongs_to_collection']['name'] ?? null,
                'part' => null,
                'total' => null,
            ];
        }

        $crew = \is_array($detail['credits']['crew'] ?? null) ? $detail['credits']['crew'] : [];
        $directors = [];
        $writers = [];
        foreach ($crew as $person) {
            if (!\is_array($person)) {
                continue;
            }
            $job = (string) ($person['job'] ?? '');
            $name = (string) ($person['name'] ?? '');
            if ('' === $name) {
                continue;
            }
            if ('Director' === $job) {
                $directors[] = $name;
            }
            if ('Screenplay' === $job || 'Writer' === $job) {
                $writers[] = $name;
            }
        }

        $details = [
            'tmdbId' => $tmdbId,
            'runtime' => isset($detail['runtime']) ? (int) $detail['runtime'] : null,
            'certification' => $this->usMovieCertification($detail['release_dates'] ?? null),
            'releaseDate' => $releaseDate,
            'directors' => $directors,
            'writers' => array_values(array_unique($writers)),
            'cast' => $this->mapCast($detail['credits'] ?? null),
        ];
        if (null !== $collection) {
            $details['collection'] = $collection;
        }

        return [
            'type' => 'movie',
            'slug' => $this->allocateSlug($slugs, 'movie', $title, $year, $tmdbId),
            'title' => $title,
            'year' => $year,
            'tagline' => $this->nullableString($detail['tagline'] ?? null),
            'overview' => $this->nullableString($detail['overview'] ?? null),
            'genres' => $this->mapGenres($detail['genres'] ?? null),
            'poster' => $this->img($detail['poster_path'] ?? null, 'w500'),
            'backdrop' => $this->img($detail['backdrop_path'] ?? null, 'w1280'),
            'trailer' => $this->pickTrailer($detail['videos'] ?? null),
            'externalScore' => isset($detail['vote_average']) ? (int) round((float) $detail['vote_average'] * 10) : null,
            'source' => ['name' => 'TMDB', 'url' => self::SITE.'/movie/'.$tmdbId],
            'originalTitle' => $this->nullableString($detail['original_title'] ?? null),
            'imdbId' => $this->imdbId($detail),
            'originalLanguage' => $this->nullableString($detail['original_language'] ?? null),
            'countries' => $this->countries($detail),
            'extras' => $this->extras($detail),
            'voteCount' => isset($detail['vote_count']) ? (int) $detail['vote_count'] : null,
            'popularity' => isset($detail['popularity']) ? (float) $detail['popularity'] : null,
            
            'addedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'details' => $details,
        ];
    }

    /**
     * @param array<string, string> $slugs
     *
     * @return array{item: array<string, mixed>, seasonNumbers: list<int>}
     */
    public function mapSeriesShell(array $detail, array &$slugs): array
    {
        $tmdbId = (int) $detail['id'];
        $firstAired = isset($detail['first_air_date']) && \is_string($detail['first_air_date']) && '' !== $detail['first_air_date']
            ? $detail['first_air_date']
            : null;
        $year = $firstAired ? (int) substr($firstAired, 0, 4) : null;
        $title = (string) ($detail['name'] ?? 'Untitled');

        $seasonNumbers = [];
        foreach ($detail['seasons'] ?? [] as $season) {
            if (!\is_array($season)) {
                continue;
            }
            $n = (int) ($season['season_number'] ?? 0);
            if ($n > 0) {
                $seasonNumbers[] = $n;
            }
        }

        $creators = [];
        foreach ($detail['created_by'] ?? [] as $person) {
            if (\is_array($person) && isset($person['name'])) {
                $creators[] = (string) $person['name'];
            }
        }

        $episodeRuntime = null;
        if (isset($detail['episode_run_time'][0])) {
            $episodeRuntime = (int) $detail['episode_run_time'][0];
        }

        $work = [
            'type' => 'series',
            'slug' => $this->allocateSlug($slugs, 'series', $title, $year, $tmdbId),
            'title' => $title,
            'year' => $year,
            'tagline' => $this->nullableString($detail['tagline'] ?? null),
            'overview' => $this->nullableString($detail['overview'] ?? null),
            'genres' => $this->mapGenres($detail['genres'] ?? null),
            'poster' => $this->img($detail['poster_path'] ?? null, 'w500'),
            'backdrop' => $this->img($detail['backdrop_path'] ?? null, 'w1280'),
            'trailer' => $this->pickTrailer($detail['videos'] ?? null),
            'externalScore' => isset($detail['vote_average']) ? (int) round((float) $detail['vote_average'] * 10) : null,
            'source' => ['name' => 'TMDB', 'url' => self::SITE.'/tv/'.$tmdbId],
            'originalTitle' => $this->nullableString($detail['original_name'] ?? null),
            'imdbId' => $this->imdbId($detail),
            'originalLanguage' => $this->nullableString($detail['original_language'] ?? null),
            'countries' => $this->countries($detail),
            'extras' => $this->extras($detail),
            'voteCount' => isset($detail['vote_count']) ? (int) $detail['vote_count'] : null,
            'popularity' => isset($detail['popularity']) ? (float) $detail['popularity'] : null,
            
            'addedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'details' => [
                'tmdbId' => $tmdbId,
                'certification' => $this->usTvCertification($detail['content_ratings'] ?? null),
                'creators' => $creators,
                'cast' => $this->mapCast($detail['credits'] ?? null),
                'firstAired' => $firstAired,
                'lastAired' => $this->nullableString($detail['last_air_date'] ?? null),
                'seasonCount' => (int) ($detail['number_of_seasons'] ?? \count($seasonNumbers)),
                'episodeCount' => (int) ($detail['number_of_episodes'] ?? 0),
                'episodeRuntime' => $episodeRuntime,
                'network' => isset($detail['networks'][0]['name']) ? (string) $detail['networks'][0]['name'] : null,
                'status' => $this->nullableString($detail['status'] ?? null),
            ],
        ];

        return ['item' => $work, 'seasonNumbers' => $seasonNumbers];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSeason(array $seasonData): array
    {
        $episodes = [];
        foreach ($seasonData['episodes'] ?? [] as $ep) {
            if (!\is_array($ep)) {
                continue;
            }
            $episodes[] = [
                'number' => (int) ($ep['episode_number'] ?? 0),
                'title' => $this->nullableString($ep['name'] ?? null),
                'airDate' => $this->nullableString($ep['air_date'] ?? null),
                'runtime' => isset($ep['runtime']) ? (int) $ep['runtime'] : null,
                'overview' => $this->nullableString($ep['overview'] ?? null),
                'still' => $this->img($ep['still_path'] ?? null, 'w300'),
            ];
        }

        $airDate = $this->nullableString($seasonData['air_date'] ?? null);

        return [
            'number' => (int) ($seasonData['season_number'] ?? 0),
            'title' => (string) ($seasonData['name'] ?? ('Season '.($seasonData['season_number'] ?? 0))),
            'overview' => $this->nullableString($seasonData['overview'] ?? null),
            'poster' => $this->img($seasonData['poster_path'] ?? null, 'w500'),
            'year' => $airDate ? (int) substr($airDate, 0, 4) : null,
            'episodes' => $episodes,
        ];
    }

    /**
     * @param array<string, string> $slugs
     */
    private function allocateSlug(array &$slugs, string $type, string $title, ?int $year, int $tmdbId): string
    {
        $itemId = ('movie' === $type ? 'movie-' : 'series-').$tmdbId;
        $base = null !== $year
            ? $this->slugify($title).'-'.$year
            : $this->slugify($title).'-'.$tmdbId;
        $key = $type.':'.$base;
        $owner = $slugs[$key] ?? null;
        if (null === $owner || $owner === $itemId) {
            $slugs[$key] = $itemId;

            return $base;
        }
        $fallback = $this->slugify($title).'-'.$tmdbId;
        $slugs[$type.':'.$fallback] = $itemId;

        return $fallback;
    }

    private function slugify(string $title): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $s = false === $s ? $title : $s;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');

        return '' !== $s ? $s : 'untitled';
    }

    private function img(mixed $path, string $size): ?string
    {
        if (!\is_string($path) || '' === $path) {
            return null;
        }

        return self::IMG.'/'.$size.$path;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /**
     * The IMDb id TMDB knows for this title — "tt0111161". Movies carry it at
     * the top level, series only when external_ids is appended. It is what the
     * IMDb ratings dataset joins on.
     *
     * @param array<string, mixed> $detail
     */
    private function imdbId(array $detail): ?string
    {
        $candidates = [
            $detail['imdb_id'] ?? null,
            $detail['external_ids']['imdb_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && preg_match('/^tt\d{5,}$/', trim($candidate))) {
                return trim($candidate);
            }
        }

        return null;
    }


    /**
     * ISO 3166-1 codes for a title, deduplicated and upper-cased.
     *
     * Two fields carry this and they disagree. `origin_country` is the newer
     * one and is the only one TV has; `production_countries` is the older, is
     * richer on films, and carries names we throw away. Both are read and
     * merged, because a co-production listed one way on a film is listed the
     * other way on the show it spun off.
     *
     * @param array<string, mixed> $detail
     *
     * @return list<string>|null
     */
    private function countries(array $detail): ?array
    {
        $codes = [];

        foreach ((array) ($detail['origin_country'] ?? []) as $code) {
            if (\is_string($code) && '' !== $code) {
                $codes[] = strtoupper($code);
            }
        }

        foreach ((array) ($detail['production_countries'] ?? []) as $row) {
            $code = \is_array($row) ? ($row['iso_3166_1'] ?? null) : null;
            if (\is_string($code) && '' !== $code) {
                $codes[] = strtoupper($code);
            }
        }

        // Two letters or nothing: TMDB is clean here, but this is written
        // straight into a filter facet and a stray value would become a chip.
        $codes = array_values(array_unique(array_filter(
            $codes,
            static fn (string $code) => 1 === preg_match('/^[A-Z]{2}$/', $code),
        )));

        return [] === $codes ? null : $codes;
    }


    /**
     * The fields TMDB has always sent that nothing used to read.
     *
     * One method for both types because most of it is shared and the rest is
     * simply absent — a film has no next episode and a series has no revenue,
     * and null for a key the type does not have is the right answer either way.
     *
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    public function extrasFor(array $detail): array
    {
        return $this->extras($detail);
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function extras(array $detail): array
    {
        $episode = static function (mixed $row): ?array {
            if (!\is_array($row)) {
                return null;
            }

            return array_filter([
                'season' => isset($row['season_number']) ? (int) $row['season_number'] : null,
                'episode' => isset($row['episode_number']) ? (int) $row['episode_number'] : null,
                'name' => \is_string($row['name'] ?? null) && '' !== $row['name'] ? $row['name'] : null,
                'airDate' => \is_string($row['air_date'] ?? null) && '' !== $row['air_date'] ? $row['air_date'] : null,
            ], static fn ($value) => null !== $value);
        };

        $next = $episode($detail['next_episode_to_air'] ?? null);
        $last = $episode($detail['last_episode_to_air'] ?? null);

        $languages = [];
        foreach ((array) ($detail['spoken_languages'] ?? []) as $row) {
            $code = \is_array($row) ? ($row['iso_639_1'] ?? null) : null;
            if (\is_string($code) && '' !== $code) {
                $languages[] = strtolower($code);
            }
        }

        return [
            // 0 means "unknown" to TMDB on both of these, not "free to make".
            'budget' => ((int) ($detail['budget'] ?? 0)) ?: null,
            'revenue' => ((int) ($detail['revenue'] ?? 0)) ?: null,
            'homepage' => \is_string($detail['homepage'] ?? null) && '' !== $detail['homepage']
                ? $detail['homepage']
                : null,
            'spokenLanguages' => array_values(array_unique($languages)) ?: null,
            'inProduction' => \array_key_exists('in_production', $detail)
                ? (bool) $detail['in_production']
                : null,
            'companies' => $this->names($detail['production_companies'] ?? null, 'name'),
            'keywords' => $this->names(
                $detail['keywords']['keywords'] ?? $detail['keywords']['results'] ?? null,
                'name',
            ),
            // Whole, not trimmed — see the note on Work::$watchProviders.
            'watchProviders' => \is_array($detail['watch/providers']['results'] ?? null)
                && [] !== $detail['watch/providers']['results']
                    ? $detail['watch/providers']['results']
                    : null,
            'similar' => $this->tmdbIds($detail['similar']['results'] ?? null),
            'recommended' => $this->tmdbIds($detail['recommendations']['results'] ?? null),
            'nextEpisodeAt' => $next['airDate'] ?? null,
            'episodesAir' => array_filter(['next' => $next, 'last' => $last]) ?: null,
        ];
    }


    /**
     * The `name` off a list of {id, name} rows — studios, keywords.
     *
     * Names rather than ids: nothing here joins on them, they are drawn as
     * chips and searched as text, and an id would need a second table to mean
     * anything.
     *
     * @return list<string>|null
     */
    private function names(mixed $rows, string $key): ?array
    {
        if (!\is_array($rows)) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $value = \is_array($row) ? ($row[$key] ?? null) : null;
            if (\is_string($value) && '' !== trim($value)) {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out)) ?: null;
    }

    /**
     * TMDB ids off a list of titles, capped.
     *
     * Twelve because that is more than any rail draws, and because this is
     * written once per work into a table that would otherwise hold forty
     * million rows to serve eight.
     *
     * @return list<int>|null
     */
    private function tmdbIds(mixed $rows, int $limit = 12): ?array
    {
        if (!\is_array($rows)) {
            return null;
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = \is_array($row) ? ($row['id'] ?? null) : null;
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
            if (\count($ids) >= $limit) {
                break;
            }
        }

        return $ids ?: null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function mapGenres(mixed $genres): array
    {
        if (!\is_array($genres)) {
            return [];
        }
        $out = [];
        foreach ($genres as $g) {
            if (\is_array($g) && isset($g['name'])) {
                $out[] = (string) $g['name'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: string, character: ?string, photo: ?string}>
     */
    private function mapCast(mixed $credits): array
    {
        if (!\is_array($credits) || !\is_array($credits['cast'] ?? null)) {
            return [];
        }
        $out = [];
        foreach (\array_slice($credits['cast'], 0, 12) as $person) {
            if (!\is_array($person) || !isset($person['name'])) {
                continue;
            }
            $out[] = [
                'name' => (string) $person['name'],
                'character' => $this->nullableString($person['character'] ?? null),
                'photo' => $this->img($person['profile_path'] ?? null, 'w185'),
            ];
        }

        return $out;
    }

    /**
     * @return array{site: string, key: string}|null
     */
    private function pickTrailer(mixed $videos): ?array
    {
        if (!\is_array($videos) || !\is_array($videos['results'] ?? null)) {
            return null;
        }
        $pick = null;
        foreach ($videos['results'] as $v) {
            if (!\is_array($v) || 'YouTube' !== ($v['site'] ?? null) || empty($v['key'])) {
                continue;
            }
            if ('Trailer' === ($v['type'] ?? null)) {
                return ['site' => 'YouTube', 'key' => (string) $v['key']];
            }
            $pick ??= ['site' => 'YouTube', 'key' => (string) $v['key']];
        }

        return $pick;
    }

    private function usMovieCertification(mixed $releaseDates): ?string
    {
        if (!\is_array($releaseDates) || !\is_array($releaseDates['results'] ?? null)) {
            return null;
        }
        foreach ($releaseDates['results'] as $country) {
            if (!\is_array($country) || 'US' !== ($country['iso_3166_1'] ?? null)) {
                continue;
            }
            foreach ($country['release_dates'] ?? [] as $row) {
                if (\is_array($row) && !empty($row['certification'])) {
                    return (string) $row['certification'];
                }
            }
        }

        return null;
    }

    private function usTvCertification(mixed $contentRatings): ?string
    {
        if (!\is_array($contentRatings) || !\is_array($contentRatings['results'] ?? null)) {
            return null;
        }
        foreach ($contentRatings['results'] as $row) {
            if (\is_array($row) && 'US' === ($row['iso_3166_1'] ?? null) && !empty($row['rating'])) {
                return (string) $row['rating'];
            }
        }

        return null;
    }
}
