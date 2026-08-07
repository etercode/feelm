<?php

namespace App\Presenter;

use App\Entity\Credit;
use App\Entity\Episode;
use App\Entity\ExternalId;
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
        return $this->build($work);
    }

    /**
     * The backdrop, resolved the same way a card's poster is.
     *
     * Exposed because the hover preview needs it without needing everything
     * else `one()` would fetch, and because which of the two columns wins is
     * this class's business rather than a controller's.
     */
    public function backdropUrl(Work $work): ?string
    {
        return $this->urls->artwork($work->getBackdropMirror(), $work->getBackdrop());
    }

    /**
     * One row of a listing — browse, search, a rail.
     *
     * Built from what a poster card actually draws, rather than from one() with
     * pieces removed. Read PosterCard and types.js and the whole list is:
     * artwork, title, the type, the fact line under it (year, runtime and
     * certification for a film; seasons and episodes for a series), a score,
     * and the two flags that decide whether it wears an UPCOMING or a NEW
     * badge.
     *
     * What that leaves out is most of the bytes. The description and tagline
     * are three quarters of a payload nobody reads until they open the title;
     * the backdrop is a second URL for an image a card never shows; genres are
     * drawn from the facet and filter endpoints, not from the rows; and the
     * external ids exist only to build a link the card does not render.
     *
     * @return array<string, mixed>
     */
    public function listItem(Work $work): array
    {
        return [
            'id' => $work->getId(),
            'type' => $work->getType(),
            'slug' => $work->getSlug(),
            'title' => $work->getTitle(),
            'year' => $work->getYear(),
            'poster' => $this->urls->artwork($work->getPosterMirror(), $work->getPoster()),
            'externalScore' => $work->getExternalScore(),
            'ratings' => $this->ratings($work),
            // Only for the label when a title has no rating rows at all — the
            // card prints the source's name beside the cached score.
            'source' => $work->getSource(),
            'details' => $this->listDetails($work),
            // The NEW badge compares this against when you last caught up.
            'addedAt' => $work->getAddedAt()?->format(\DateTimeInterface::ATOM),
            'isUpcoming' => $work->isUpcoming(),
        ];
    }

    /**
     * The fact line under a card, and nothing else.
     *
     * Each type prints three things (see types.js `line`). Everything else a
     * detail page asks for arrives when the page is opened.
     *
     * @return array<string, mixed>
     */
    private function listDetails(Work $work): array
    {
        $extra = $work->getExtra();

        $details = [
            'releaseDate' => $work->getReleaseDate()?->format('Y-m-d'),
        ];

        switch ($work->getType()) {
            case 'series':
                $details['seasonCount'] = $extra['seasonCount'] ?? null;
                $details['episodeCount'] = $extra['episodeCount'] ?? null;
                break;
            case 'game':
                $details['developers'] = $this->names($work, Credit::ROLE_DEVELOPER) ?: null;
                $details['perspectives'] = $extra['perspectives'] ?? null;
                break;
            case 'book':
                $details['authors'] = $this->names($work, Credit::ROLE_AUTHOR) ?: null;
                $details['pages'] = $work->getPageCount();
                break;
            default:
                $details['runtime'] = $work->getRuntimeMinutes();
                $details['certification'] = $work->getCertification();
        }

        return array_filter($details, static fn ($value) => null !== $value);
    }

    /**
     * A row in the search overlay: artwork, a name, its year, and where it goes.
     *
     * Trimmed from the full list row, which was 25 KB of descriptions, scores
     * and dates behind a dropdown. Not trimmed past the subtitle, though: the
     * row draws "2010 · 2h 28m" under the title, and that line is what tells
     * two films of the same name apart — which is the entire reason somebody is
     * looking at a list of matches rather than the first one.
     *
     * `details` therefore has to be present and shaped per type, because the
     * client reads it positionally — item.details.runtime, .seasonCount — and
     * an absent one is a TypeError in the middle of a render, not a blank line.
     *
     * @return array<string, mixed>
     */
    public function suggestion(Work $work): array
    {
        return [
            'id' => $work->getId(),
            'type' => $work->getType(),
            'slug' => $work->getSlug(),
            'title' => $work->getTitle(),
            'year' => $work->getYear(),
            'poster' => $this->urls->artwork($work->getPosterMirror(), $work->getPoster()),
            'details' => $this->listDetails($work),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Work $work): array
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
            'poster' => $this->urls->artwork($work->getPosterMirror(), $work->getPoster()),
            'backdrop' => $this->urls->artwork($work->getBackdropMirror(), $work->getBackdrop()),
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
     * A title on the home page's release queue.
     *
     * The queue draws a plate and a list: artwork, name, genres, when it lands,
     * and who directed it. It has never shown the description, the cast, the
     * runtime or where the row came from — all of which the full payload sends
     * anyway, at about 1.3 KB a title.
     *
     * The rating fields stay. An upcoming title can be on somebody's shelf, and
     * the poster card that draws it there reads them.
     *
     * @return array<string, mixed>
     */
    public function upcoming(Work $work): array
    {
        $payload = [
            'id' => $work->getId(),
            'type' => $work->getType(),
            'slug' => $work->getSlug(),
            'title' => $work->getTitle(),
            'year' => $work->getYear(),
            'genres' => $work->getGenreNames(),
            'poster' => $this->urls->artwork($work->getPosterMirror(), $work->getPoster()),
            'backdrop' => $this->urls->artwork($work->getBackdropMirror(), $work->getBackdrop()),
            'externalScore' => $work->getExternalScore(),
            'ratings' => $this->ratings($work),
            'details' => array_filter([
                'releaseDate' => $work->getReleaseDate()?->format('Y-m-d'),
                'directors' => $this->names($work, Credit::ROLE_DIRECTOR) ?: null,
            ], static fn ($value) => null !== $value),
            'isUpcoming' => true,
        ];

        /*
         * The plate on the home page plays this. It was never sent here, and
         * the only reason some releases played anyway is that a title also
         * sitting in one of the popularity rails picked one up from the list
         * payload — so whether the hero had a trailer depended on how popular
         * the film was. It is two short strings; send it.
         */
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
            'poster' => $this->urls->artwork($work->getPosterMirror(), $work->getPoster()),
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
            // Why it is hidden, not a second kind of hidden — see WorkAdmin.
            'adult' => $work->isAdult(),
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
            'backdrop' => $this->urls->artwork($work->getBackdropMirror(), $work->getBackdrop()),
            'posterPath' => $work->getPoster(),
            'backdropPath' => $work->getBackdrop(),
            'releaseDate' => $work->getReleaseDate()?->format('Y-m-d'),
            'pageCount' => $work->getPageCount(),
            'publisher' => $work->getPublisher(),
            'genres' => $work->getGenreNames(),
            'ratings' => $this->ratings($work),
            /*
             * Admin only. Whether the IMDb row is held against the dataset
             * import is something the edit form has to show — otherwise a
             * locked title looks identical to one nobody has touched — but it
             * is nothing to the public payload, which is a poster and a number.
             */
            'imdbLocked' => $work->getRating(WorkRating::SOURCE_IMDB)?->isLocked() ?? false,
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
                /*
                 * Television is stored under its own source because TMDB's two
                 * id spaces collide (see ExternalId::SOURCE_TMDB_TV), but that
                 * is a storage concern. Outside it is one site, and the rating
                 * rows this map is read against are keyed 'tmdb' whatever the
                 * type — so a series' link would otherwise silently not resolve.
                 */
                $ids[ExternalId::SOURCE_TMDB_TV === $source ? ExternalId::SOURCE_TMDB : $source] = $value;
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

        /*
         * The columns the details backfill filled and nothing ever read.
         *
         * Sent only when they hold something. A film with no budget on record
         * should not carry `"budget": 0` into the payload for the detail sheet
         * to have to decide is meaningless — absent says that better, and the
         * sheet already draws only the facts it was given.
         */
        foreach ([
            'budget' => $work->getBudget(),
            'revenue' => $work->getRevenue(),
        ] as $key => $amount) {
            if (null !== $amount && $amount > 0) {
                $details[$key] = $amount;
            }
        }

        if (null !== ($homepage = $work->getHomepage()) && '' !== $homepage) {
            $details['homepage'] = $homepage;
        }

        if ([] !== ($spoken = $work->getSpokenLanguages() ?? [])) {
            $details['spokenLanguages'] = $spoken;
        }

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
     * The slug carries because a cast list is a set of links now — see the
     * person page. Without it every name is a dead end, which is what it was.
     *
     * @return list<array{slug: string|null, name: string, character: string|null, photo: string|null}>
     */
    private function cast(Work $work): array
    {
        return array_map(fn (Credit $credit) => [
            'slug' => $credit->getPerson()?->getSlug(),
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
