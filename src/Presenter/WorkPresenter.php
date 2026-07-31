<?php

namespace App\Presenter;

use App\Entity\Credit;
use App\Entity\Episode;
use App\Entity\Season;
use App\Entity\Work;
use App\Entity\WorkRating;
use App\Service\PublicUrlGenerator;

/**
 * Turns a Work into the JSON the frontend has always read.
 *
 * The storage got normalised; the wire format did not. Genres come back as a
 * list of names and the type-specific facts come back under `details`, because
 * that is the contract $lib/data/types.js is built on — columns and join tables
 * are an implementation detail behind it.
 */
final class WorkPresenter
{
    public function __construct(
        private readonly PublicUrlGenerator $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function one(Work $work): array
    {
        $payload = [
            'id' => $work->getId(),
            'type' => $work->getType(),
            'slug' => $work->getSlug(),
            'title' => $work->getTitle(),
            'originalTitle' => $work->getOriginalTitle(),
            'year' => $work->getYear(),
            'tagline' => $work->getTagline(),
            'overview' => $work->getOverview(),
            'genres' => $work->getGenreNames(),
            'poster' => $this->urls->media($work->getPoster()),
            'backdrop' => $this->urls->media($work->getBackdrop()),
            'externalScore' => $work->getExternalScore(),
            'ratings' => $this->ratings($work),
            'externalIds' => $this->externalIds($work),
            'source' => $work->getSource(),
            'details' => $this->details($work),
            'addedAt' => $work->getAddedAt()?->format(\DateTimeInterface::ATOM),
            'isUpcoming' => $work->isUpcoming(),
        ];

        if (null !== $work->getTrailer()) {
            $payload['trailer'] = $work->getTrailer();
        }

        return $payload;
    }

    /**
     * Just enough to name a title and link to it.
     *
     * Admin tables list works next to something else — a review, a credit — and
     * one() would drag genres, ratings, credits and seasons along for a poster
     * and a title. This touches no relation at all, so a page of rows is one
     * query rather than a preload.
     *
     * @return array<string, mixed>
     */
    public function compact(Work $work): array
    {
        return [
            'id' => $work->getId(),
            'type' => $work->getType(),
            'slug' => $work->getSlug(),
            'title' => $work->getTitle(),
            'year' => $work->getYear(),
            'poster' => $this->urls->media($work->getPoster()),
        ];
    }

    /**
     * One row of the admin's works table.
     *
     * Scalars only — deliberately touches no relation. Genres, ratings, credits
     * and external ids are all lazy, and a page of fifty rows reaching for any
     * of them is fifty extra queries. The row carries what a table cell can
     * show; the rest arrives when a work is opened.
     *
     * @return array<string, mixed>
     */
    public function adminRow(Work $work): array
    {
        return [
            ...$this->compact($work),
            'originalTitle' => $work->getOriginalTitle(),
            'popularity' => $work->getPopularity(),
            'externalScore' => $work->getExternalScore(),
            'voteCount' => $work->getVoteCount(),
            'certification' => $work->getCertification(),
            'originalLanguage' => $work->getOriginalLanguage(),
            'runtimeMinutes' => $work->getRuntimeMinutes(),
            'hasOverview' => '' !== trim((string) $work->getOverview()),
            'addedAt' => $work->getAddedAt()?->format(\DateTimeInterface::ATOM),
            'deletedAt' => $work->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            'hidden' => $work->isDeleted(),
        ];
    }

    /**
     * A work opened in the admin: the row, plus everything editable and the
     * relations worth seeing. One work, so the lazy loads are affordable.
     *
     * @return array<string, mixed>
     */
    public function adminOne(Work $work): array
    {
        return [
            ...$this->adminRow($work),
            'tagline' => $work->getTagline(),
            'overview' => $work->getOverview(),
            'backdrop' => $this->urls->media($work->getBackdrop()),
            'posterPath' => $work->getPoster(),
            'backdropPath' => $work->getBackdrop(),
            'releaseDate' => $work->getReleaseDate()?->format('Y-m-d'),
            'pageCount' => $work->getPageCount(),
            'publisher' => $work->getPublisher(),
            'genres' => $work->getGenreNames(),
            'ratings' => $this->ratings($work),
            'externalIds' => $this->externalIds($work),
            'source' => $work->getSource(),
            'isUpcoming' => $work->isUpcoming(),
        ];
    }

    /**
     * The ids other sites know this by, so the UI can link out to them.
     *
     * @return array<string, string>
     */
    private function externalIds(Work $work): array
    {
        $ids = [];
        foreach ($work->getExternalIds() as $external) {
            $source = $external->getSource();
            $value = $external->getExternalId();
            if (null !== $source && null !== $value) {
                $ids[$source] = $value;
            }
        }

        return $ids;
    }

    /**
     * What each source thinks, in that source's own units — IMDb 7.4 out of 10
     * reads as itself rather than as 74%.
     *
     * @return array<string, array{rating: float, scale: int, votes: int|null}>
     */
    private function ratings(Work $work): array
    {
        $ratings = [];
        foreach ($work->getRatings() as $rating) {
            $source = $rating->getSource();
            $value = $rating->getRating();
            if (null === $source || null === $value) {
                continue;
            }
            $ratings[$source] = [
                'rating' => $value,
                'scale' => $rating->getScale(),
                'votes' => $rating->getVotes(),
            ];
        }

        // A stable order so the UI can take the first without sorting.
        $ordered = [];
        foreach (WorkRating::PREFERENCE as $source) {
            if (isset($ratings[$source])) {
                $ordered[$source] = $ratings[$source];
            }
        }

        return $ordered + $ratings;
    }

    /**
     * The type-specific block: columns and credits reassembled into the shape
     * the type registry expects.
     *
     * @return array<string, mixed>
     */
    private function details(Work $work): array
    {
        // Display-only leftovers first, so a real column always wins.
        $details = $work->getExtra();

        $details['runtime'] = $work->getRuntimeMinutes();
        $details['certification'] = $work->getCertification();
        $details['releaseDate'] = $work->getReleaseDate()?->format('Y-m-d');
        $details['originalLanguage'] = $work->getOriginalLanguage();

        foreach ([
            'directors' => Credit::ROLE_DIRECTOR,
            'writers' => Credit::ROLE_WRITER,
            'creators' => Credit::ROLE_CREATOR,
            'authors' => Credit::ROLE_AUTHOR,
            'developers' => Credit::ROLE_DEVELOPER,
        ] as $key => $role) {
            $names = $this->names($work, $role);
            if ([] !== $names) {
                $details[$key] = $names;
            }
        }

        $cast = $this->cast($work);
        if ([] !== $cast) {
            $details['cast'] = $cast;
        }

        if (null !== $work->getPageCount()) {
            $details['pages'] = $work->getPageCount();
        }

        if (null !== $work->getPublisher()) {
            $details['publisher'] = $work->getPublisher();
            // Games list publishers; books name one.
            $details['publishers'] = array_values(array_unique(array_merge(
                $this->names($work, Credit::ROLE_PUBLISHER),
                [$work->getPublisher()],
            )));
        }

        if ('series' === $work->getType() && $work->getSeasons()->count() > 0) {
            $details['seasons'] = array_map(
                fn (Season $season) => $this->season($season),
                $work->getSeasons()->toArray(),
            );
            $episodes = 0;
            foreach ($work->getSeasons() as $season) {
                $episodes += $season->getEpisodes()->count();
            }
            $details['seasonCount'] ??= $work->getSeasons()->count();
            $details['episodeCount'] ??= $episodes;
        }

        return array_filter(
            $details,
            static fn ($value) => null !== $value && [] !== $value,
        );
    }

    /**
     * @return list<string>
     */
    private function names(Work $work, string $role): array
    {
        return array_values(array_map(
            static fn (Credit $credit) => (string) $credit->getPerson()?->getName(),
            $work->getCreditsWithRole($role),
        ));
    }

    /**
     * @return list<array{name: string, character: string|null, photo: string|null}>
     */
    private function cast(Work $work): array
    {
        return array_map(fn (Credit $credit) => [
            'name' => (string) $credit->getPerson()?->getName(),
            'character' => $credit->getCharacterName(),
            'photo' => $this->urls->media($credit->getPerson()?->getPhoto()),
        ], $work->getCreditsWithRole(Credit::ROLE_CAST));
    }

    /**
     * @return array<string, mixed>
     */
    private function season(Season $season): array
    {
        // A season has no year of its own; its first episode to air is its year.
        $year = null;
        foreach ($season->getEpisodes() as $episode) {
            $aired = $episode->getAirDate();
            if (null !== $aired) {
                $year = (int) $aired->format('Y');
                break;
            }
        }

        return [
            'number' => $season->getNumber(),
            'name' => $season->getTitle() ?? 'Season '.$season->getNumber(),
            'title' => $season->getTitle(),
            'year' => $year,
            'overview' => $season->getOverview(),
            'poster' => $this->urls->media($season->getPoster()),
            'episodes' => array_map(
                fn (Episode $episode) => [
                    'number' => $episode->getNumber(),
                    'title' => $episode->getTitle(),
                    'runtime' => $episode->getRuntime(),
                    'airDate' => $episode->getAirDate()?->format('Y-m-d'),
                    'overview' => $episode->getOverview(),
                ],
                $season->getEpisodes()->toArray(),
            ),
        ];
    }
}
