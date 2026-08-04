<?php

namespace App\Service\Catalog;

use App\Entity\Credit;
use App\Entity\Episode;
use App\Entity\ExternalId;
use App\Entity\Season;
use App\Entity\Work;
use App\Entity\WorkRating;
use App\Repository\GenreRepository;
use App\Repository\PersonRepository;
use App\Repository\WorkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Upserts catalog rows — from the TMDB crawl or a seed file — into the
 * normalised tables.
 *
 * This is the one place that knows how a source row maps onto the schema:
 * scalars go to columns, genre names become genre rows, credited people become
 * person + credit rows, and whatever is left over (collection, platforms, ISBN)
 * stays in `extra` for display.
 *
 * Identity comes from external_ids, not from a slug or a source URL, so a
 * re-crawl updates the row it created last time even if the title was renamed.
 */
final class CatalogWorkPersister
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkRepository $works,
        private readonly GenreRepository $genres,
        private readonly PersonRepository $people,
    ) {
    }

    /**
     * @param array<string, mixed> $row mapped work (mapper shape)
     */
    public function persist(array $row): Work
    {
        $type = (string) ($row['type'] ?? '');
        $slug = (string) ($row['slug'] ?? '');
        if ('' === $type || '' === $slug) {
            throw new \InvalidArgumentException('A work needs a type and a slug.');
        }

        $details = \is_array($row['details'] ?? null) ? $row['details'] : [];
        $tmdbId = $this->tmdbIdFromRow($row, $details);

        /*
         * Identity is the source's id, never the slug.
         *
         * Two different films can share a title and a year — TMDB has plenty —
         * so falling back to "a row already has this slug" would hand us
         * somebody else's row and overwrite it. Only rows with no external id
         * at all (hand-written seed data) may be matched that way.
         */
        // Films and television are numbered separately by TMDB — see
        // ExternalId::SOURCE_TMDB_TV. Looking a series up in the movie id space
        // finds an unrelated film and rewrites it.
        $tmdbSource = ExternalId::tmdbFor($type);

        $existing = null !== $tmdbId
            ? $this->works->findOneByExternalId($tmdbSource, (string) $tmdbId)
            : $this->works->findOneByTypeAndSlug($type, $slug);

        $work = $existing ?? (new Work())->setType($type);
        if (null === $existing) {
            $this->entityManager->persist($work);
        }

        // Keep the slug a row was first published under; only new rows claim one.
        if (null === $existing || null === $work->getSlug()) {
            $work->setSlug($this->uniqueSlug($type, $slug, $tmdbId, $work));
        }

        $seasonsData = [];
        if ('series' === $type && \is_array($details['seasons'] ?? null)) {
            $seasonsData = $details['seasons'];
        }

        $work
            ->setTitle($this->cut((string) ($row['title'] ?? $slug), 255))
            ->setOriginalTitle($this->str($row['originalTitle'] ?? null, 255))
            ->setYear(isset($row['year']) ? (int) $row['year'] : null)
            ->setTagline($this->str($row['tagline'] ?? null, 500))
            ->setOverview($this->str($row['overview'] ?? null))
            ->setPoster($this->str($row['poster'] ?? null, 500))
            ->setBackdrop($this->str($row['backdrop'] ?? null, 500))
            ->setTrailer($this->asObject($row['trailer'] ?? null))
            ->setVoteCount(isset($row['voteCount']) ? (int) $row['voteCount'] : null)
            ->setPopularity(isset($row['popularity']) ? (float) $row['popularity'] : null)
            ->setOriginalLanguage($this->str($row['originalLanguage'] ?? null, 8))
            ->setBudget($row['extras']['budget'] ?? null)
            ->setRevenue($row['extras']['revenue'] ?? null)
            ->setHomepage($this->str($row['extras']['homepage'] ?? null, 500))
            ->setSpokenLanguages($row['extras']['spokenLanguages'] ?? null)
            ->setInProduction($row['extras']['inProduction'] ?? null)
            ->setNextEpisodeAt($this->date($row['extras']['nextEpisodeAt'] ?? null))
            ->setEpisodesAir($row['extras']['episodesAir'] ?? null)
            ->setWatchProviders($row['extras']['watchProviders'] ?? null)
            ->setSource($this->asObject($row['source'] ?? null));

        // Fields the mapper may put at the top level or leave inside details.
        $work
            ->setRuntimeMinutes($this->int($row['runtime'] ?? $details['runtime'] ?? null))
            ->setCertification($this->str($row['certification'] ?? $details['certification'] ?? null, 16))
            ->setReleaseDate($this->date(
                $row['releaseDate'] ?? $details['releaseDate'] ?? $details['firstAired'] ?? null,
            ))
            ->setPageCount($this->int($row['pages'] ?? $details['pages'] ?? null))
            ->setPublisher($this->str($row['publisher'] ?? $details['publisher'] ?? null, 180));

        $this->syncRating($work, $row);
        $this->syncGenres($work, $row['genres'] ?? []);
        $this->syncCredits($work, $row, $details);
        $this->syncExternalId($work, $tmdbSource, null !== $tmdbId ? (string) $tmdbId : null);
        $this->syncExternalId($work, ExternalId::SOURCE_IMDB, $this->str($row['imdbId'] ?? $details['imdbId'] ?? null), verifyFree: true);

        // Only what no column and no relation owns.
        $work->setExtra($this->leftovers($details));

        if (null === $existing && \is_string($row['addedAt'] ?? null)) {
            try {
                $work->setAddedAt(new \DateTimeImmutable($row['addedAt']));
            } catch (\Exception) {
                // Keep the constructor default.
            }
        }

        if ('series' === $type && [] !== $seasonsData) {
            $this->replaceSeasons($work, $seasonsData);
        }

        return $work;
    }

    /**
     * Merge one season into an existing series (does not wipe other seasons).
     *
     * @param array<string, mixed> $seasonRow
     */
    public function upsertSeason(Work $work, array $seasonRow): void
    {
        $number = (int) ($seasonRow['number'] ?? 0);
        foreach ($work->getSeasons()->toArray() as $season) {
            if ($season->getNumber() === $number) {
                $work->getSeasons()->removeElement($season);
                $this->entityManager->flush();
                break;
            }
        }

        /*
         * Titles and overviews are TEXT and take whatever TMDB sends. The
         * poster is a path into their CDN and stays bounded — 500 is many
         * times the length of a real one, so a value over it is broken rather
         * than long, and cutting it is how a broken value stops being able to
         * fail the flush and take the whole series down with it.
         */
        $season = (new Season())
            ->setNumber($number)
            ->setTitle($this->str($seasonRow['title'] ?? $seasonRow['name'] ?? null))
            ->setOverview($this->str($seasonRow['overview'] ?? null))
            ->setPoster($this->str($seasonRow['poster'] ?? null, 500));
        $work->addSeason($season);

        foreach ($seasonRow['episodes'] ?? [] as $episodeRow) {
            if (!\is_array($episodeRow)) {
                continue;
            }
            $episode = (new Episode())
                ->setNumber((int) ($episodeRow['number'] ?? 0))
                ->setTitle($this->str($episodeRow['title'] ?? null))
                ->setRuntime($this->int($episodeRow['runtime'] ?? null))
                ->setOverview($this->str($episodeRow['overview'] ?? null));

            $aired = $this->date($episodeRow['airDate'] ?? null);
            if (null !== $aired) {
                $episode->setAirDate($aired);
            }

            $season->addEpisode($episode);
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * Recovers from a failed flush: Doctrine shuts the entity manager when one
     * throws, and everything after it would fail until the manager is replaced.
     */
    public function reset(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->registry->resetManager();
        } else {
            $this->entityManager->clear();
        }

        $this->genres->resetPending();
        $this->people->resetPending();
    }

    public function clear(): void
    {
        $this->entityManager->clear();
        // Detached objects must not be handed out again after a clear.
        $this->genres->resetPending();
        $this->people->resetPending();
    }

    /* ------------------------------------------------------------ relations */

    /**
     * @param mixed $names list of genre names
     */
    private function syncGenres(Work $work, mixed $names): void
    {
        if (!\is_array($names)) {
            return;
        }

        $wanted = $this->genres->findOrCreateMany(array_map('strval', $names));

        foreach ($work->getGenres()->toArray() as $current) {
            if (!\in_array($current, $wanted, true)) {
                $work->removeGenre($current);
            }
        }
        foreach ($wanted as $genre) {
            $work->addGenre($genre);
        }
    }

    /**
     * Credits are replaced wholesale: the source is authoritative about who
     * worked on something, and a diff would cost more than a rewrite.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $details
     */

    /**
     * Every person named by a title's credits, cast and crew together.
     *
     * Collected before anything is looked up so the whole set can be fetched at
     * once. Names are returned as given; the repository owns how they turn into
     * keys.
     *
     * @param mixed                       $cast
     * @param array<string, mixed>        $crew
     *
     * @return list<string>
     */
    private function creditNames(mixed $cast, array $crew): array
    {
        $names = [];

        if (\is_array($cast)) {
            foreach ($cast as $member) {
                if (\is_array($member)) {
                    $name = trim((string) ($member['name'] ?? ''));
                    if ('' !== $name) {
                        $names[] = $name;
                    }
                }
            }
        }

        foreach ($crew as $list) {
            if (!\is_array($list)) {
                continue;
            }
            foreach ($list as $name) {
                $name = trim((string) $name);
                if ('' !== $name) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    private function syncCredits(Work $work, array $row, array $details): void
    {
        $cast = $row['cast'] ?? $details['cast'] ?? null;
        $crew = [
            Credit::ROLE_DIRECTOR => $row['directors'] ?? $details['directors'] ?? [],
            Credit::ROLE_WRITER => $row['writers'] ?? $details['writers'] ?? [],
            Credit::ROLE_CREATOR => $row['creators'] ?? $details['creators'] ?? [],
            Credit::ROLE_AUTHOR => $row['authors'] ?? $details['authors'] ?? [],
            Credit::ROLE_DEVELOPER => $row['developers'] ?? $details['developers'] ?? [],
            Credit::ROLE_PUBLISHER => $row['publishers'] ?? $details['publishers'] ?? [],
        ];

        // Nothing to say about credits? Leave whatever is stored alone.
        if (!\is_array($cast) && [] === array_filter($crew, static fn ($list) => \is_array($list) && [] !== $list)) {
            return;
        }

        foreach ($work->getCredits()->toArray() as $credit) {
            $work->getCredits()->removeElement($credit);
            $this->entityManager->remove($credit);
        }

        // One query for everyone this title credits, instead of one per name.
        $this->people->warm($this->creditNames($cast, $crew));

        /*
         * TMDB happily lists the same actor twice — two roles in one film, or a
         * duplicate entry with no character at all. The unique index would
         * reject the second one, so collapse them here.
         */
        $taken = [];

        if (\is_array($cast)) {
            $position = 0;
            foreach ($cast as $member) {
                if (!\is_array($member)) {
                    continue;
                }
                $name = trim((string) ($member['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $person = $this->people->findOrCreate(
                    $name,
                    $this->str($member['photo'] ?? null),
                    isset($member['tmdbId']) ? (string) $member['tmdbId'] : null,
                );
                $character = $this->str($member['character'] ?? null, 255);
                $key = Credit::ROLE_CAST.'|'.$person->getSlug().'|'.$character;
                if (isset($taken[$key])) {
                    continue;
                }
                $taken[$key] = true;

                $credit = (new Credit())
                    ->setPerson($person)
                    ->setRole(Credit::ROLE_CAST)
                    ->setCharacterName($character)
                    ->setPosition($position++);
                $this->entityManager->persist($credit);
                $work->addCredit($credit);
            }
        }

        foreach ($crew as $role => $names) {
            if (!\is_array($names)) {
                continue;
            }
            $position = 0;
            foreach ($names as $name) {
                $name = trim((string) $name);
                if ('' === $name) {
                    continue;
                }
                $person = $this->people->findOrCreate($name);
                $key = $role.'|'.$person->getSlug().'|';
                if (isset($taken[$key])) {
                    continue;
                }
                $taken[$key] = true;

                $credit = (new Credit())
                    ->setPerson($person)
                    ->setRole($role)
                    ->setPosition($position++);
                $this->entityManager->persist($credit);
                $work->addCredit($credit);
            }
        }
    }

    /**
     * TMDB's own score, stored as a rating row like every other source. The
     * cached works.external_score follows from it through a database trigger.
     *
     * @param array<string, mixed> $row
     */
    private function syncRating(Work $work, array $row): void
    {
        if (!isset($row['externalScore']) || !is_numeric($row['externalScore'])) {
            return;
        }

        $rating = $work->getRating(WorkRating::SOURCE_TMDB);
        if (null === $rating) {
            $rating = new WorkRating(WorkRating::SOURCE_TMDB);
            $this->entityManager->persist($rating);
            $work->addRating($rating);
        }

        // TMDB publishes a percentage, so the scale is 100 rather than 10.
        $rating
            ->setRating((float) $row['externalScore'])
            ->setScale(100)
            ->setVotes(isset($row['voteCount']) ? (int) $row['voteCount'] : null)
            ->touch();
    }

    /**
     * @param bool $verifyFree check the id is not already another work's before
     *                         writing it — for ids the source only reports,
     *                         rather than the one this row was found by
     */
    private function syncExternalId(Work $work, string $source, ?string $externalId, bool $verifyFree = false): void
    {
        if (null === $externalId || '' === $externalId) {
            return;
        }

        foreach ($work->getExternalIds() as $existing) {
            if ($existing->getSource() === $source) {
                if ($existing->getExternalId() === $externalId) {
                    return;
                }
                if ($verifyFree && $this->claimed($work, $source, $externalId)) {
                    return;
                }
                $existing->setExternalId($externalId);

                return;
            }
        }

        if ($verifyFree && $this->claimed($work, $source, $externalId)) {
            return;
        }

        $identifier = new ExternalId($source, $externalId);
        $this->entityManager->persist($identifier);
        $work->addExternalId($identifier);
    }

    /**
     * Does another work already hold this id?
     *
     * TMDB gets IMDb ids wrong. It gives the anime Atashin'chi tt1127682, which
     * IMDb says is the film 18 Grams of Love — and the unique constraint on
     * (source, external_id) means writing the second one throws. That killed
     * the whole title: the flush fails, Doctrine shuts the entity manager, and
     * a series is lost over a cross-reference it did not need.
     *
     * The IMDb id is something a source reports, not what identifies the row —
     * that is the TMDB id, which this work was looked up by and so cannot
     * collide. So a contested IMDb id is simply not written, and the title is
     * stored without it.
     *
     * One indexed lookup per title, and only when the id is new or changed.
     */
    private function claimed(Work $work, string $source, string $externalId): bool
    {
        $holder = $this->works->findOneByExternalId($source, $externalId);

        return null !== $holder && $holder !== $work;
    }

    /**
     * @param list<array<string, mixed>> $seasonsData
     */
    private function replaceSeasons(Work $work, array $seasonsData): void
    {
        if ($work->getSeasons()->count() > 0) {
            foreach ($work->getSeasons()->toArray() as $season) {
                $work->getSeasons()->removeElement($season);
            }
            $this->entityManager->flush();
        }

        foreach ($seasonsData as $seasonRow) {
            if (\is_array($seasonRow)) {
                $this->upsertSeason($work, $seasonRow);
            }
        }
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Details minus everything that now lives in a column or a table.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function leftovers(array $details): array
    {
        foreach ([
            'tmdbId', 'imdbId', 'cast', 'directors', 'writers', 'creators', 'authors', 'developers',
            'publishers', 'publisher', 'runtime', 'certification', 'releaseDate', 'firstAired',
            'pages', 'seasons',
        ] as $key) {
            unset($details[$key]);
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $details
     */
    private function tmdbIdFromRow(array $row, array $details): ?int
    {
        if (is_numeric($details['tmdbId'] ?? null)) {
            return (int) $details['tmdbId'];
        }
        if (is_numeric($row['tmdbId'] ?? null)) {
            return (int) $row['tmdbId'];
        }
        // Last resort for rows written before external_ids existed.
        $url = $row['source']['url'] ?? null;
        if (\is_string($url) && preg_match('#/(?:movie|tv)/(\d+)#', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * A slug no other work of this type holds.
     *
     * Appending the source id is enough in practice — two films cannot share a
     * TMDB id — but the counter is there so this can never return a duplicate
     * and stall a crawl on a unique violation.
     */
    private function uniqueSlug(string $type, string $base, ?int $tmdbId, Work $work): string
    {
        $base = $this->cut($base, 170);
        $candidates = [$base];
        if (null !== $tmdbId) {
            $candidates[] = $base.'-'.$tmdbId;
        }
        for ($suffix = 2; $suffix <= 20; ++$suffix) {
            $candidates[] = $base.'-'.$suffix;
        }

        foreach ($candidates as $candidate) {
            $holder = $this->works->findOneByTypeAndSlug($type, $candidate);
            if (null === $holder || $holder === $work) {
                return $candidate;
            }
        }

        // Nothing left to try: fall back to something that cannot collide.
        return $this->cut($base, 150).'-'.bin2hex(random_bytes(6));
    }

    /** Cuts a string to what the column can hold, on a character boundary. */
    private function cut(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max)) : $value;
    }

    private function str(mixed $value, int $max = 0): ?string
    {
        if (!\is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        return $max > 0 ? $this->cut($value, $max) : $value;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function asObject(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }
}
