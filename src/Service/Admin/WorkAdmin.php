<?php

namespace App\Service\Admin;

use App\Dto\Admin\AdminWorkRequest;
use App\Entity\Work;
use App\Entity\WorkRating;
use App\Repository\GenreRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Editing, hiding and restoring a catalogued work.
 *
 * Hiding rather than deleting is the whole design. Entries, reviews and seen
 * marks all cascade from a work, so a real DELETE of one duplicated row takes
 * other people's scores and writing with it and cannot be undone. Everything
 * here is reversible.
 *
 * What it deliberately does not touch: the type and slug, which are identity
 * and are in every link anybody has; external_score, which a database trigger
 * owns; and popularity and vote counts, which are TMDB's measurements rather
 * than anybody's opinion.
 */
final class WorkAdmin
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GenreRepository $genres,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function update(Work $work, AdminWorkRequest $payload): Work
    {
        if (null !== $payload->title) {
            $title = trim($payload->title);
            if ('' === $title) {
                throw new \InvalidArgumentException('title_required');
            }
            $work->setTitle($title);
        }

        if (null !== $payload->originalTitle) {
            $work->setOriginalTitle($this->cleaned($payload->originalTitle));
        }

        if (null !== $payload->year) {
            $work->setYear($payload->year);
        }

        if (null !== $payload->releaseDate) {
            $work->setReleaseDate($this->date($payload->releaseDate));
        }

        if (null !== $payload->tagline) {
            $work->setTagline($this->cleaned($payload->tagline));
        }

        if (null !== $payload->overview) {
            $work->setOverview($this->cleaned($payload->overview));
        }

        if (null !== $payload->poster) {
            $work->setPoster($this->cleaned($payload->poster));
        }

        if (null !== $payload->backdrop) {
            $work->setBackdrop($this->cleaned($payload->backdrop));
        }

        if (null !== $payload->runtimeMinutes) {
            $work->setRuntimeMinutes($payload->runtimeMinutes ?: null);
        }

        if (null !== $payload->certification) {
            $work->setCertification($this->cleaned($payload->certification));
        }

        if (null !== $payload->originalLanguage) {
            $work->setOriginalLanguage($this->cleaned($payload->originalLanguage));
        }

        if (null !== $payload->pageCount) {
            $work->setPageCount($payload->pageCount ?: null);
        }

        if (null !== $payload->publisher) {
            $work->setPublisher($this->cleaned($payload->publisher));
        }

        if (null !== $payload->genres) {
            $this->replaceGenres($work, $payload->genres);
        }

        $ratingChanged = $this->applyImdb($work, $payload);

        $this->entityManager->flush();

        /*
         * external_score is computed by a trigger, and the entity was loaded
         * before that trigger ran, so the copy in memory is one edit stale.
         * Without this the form saves 8.1 and reports the score it had five
         * seconds ago, which reads as the save not having worked.
         */
        if ($ratingChanged) {
            $this->entityManager->refresh($work);
        }

        return $work;
    }

    /**
     * Correcting what IMDb says about a title.
     *
     * Setting a rating locks it, because a correction that the next dataset
     * import quietly reverses is worse than no correction at all — the number
     * changes back weeks later and nobody remembers why. Unlocking is the way
     * back: pass imdbLocked false and the next import takes the title over
     * again.
     *
     * external_score follows on its own; a database trigger owns it.
     *
     * @return bool whether anything changed, so the caller knows to re-read the
     *              score the trigger writes
     */
    private function applyImdb(Work $work, AdminWorkRequest $payload): bool
    {
        if (null === $payload->imdbRating && null === $payload->imdbVotes && null === $payload->imdbLocked) {
            return false;
        }

        $rating = $work->getRating(WorkRating::SOURCE_IMDB);

        if (null === $rating) {
            if (null === $payload->imdbRating) {
                // Nothing to unlock, and votes alone are not a rating.
                return false;
            }

            $rating = (new WorkRating(WorkRating::SOURCE_IMDB))->setScale(10);
            $work->addRating($rating);
            $this->entityManager->persist($rating);
        }

        if (null !== $payload->imdbRating) {
            $rating->setRating($payload->imdbRating);
        }

        if (null !== $payload->imdbVotes) {
            $rating->setVotes($payload->imdbVotes);
        }

        $rating->setLocked($payload->imdbLocked ?? true);
        $rating->touch();

        return true;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function hide(Work $work): void
    {
        if ($work->isDeleted()) {
            throw new \InvalidArgumentException('already_deleted');
        }

        $work->softDelete();
        $this->entityManager->flush();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function restore(Work $work): void
    {
        if (!$work->isDeleted()) {
            throw new \InvalidArgumentException('not_deleted');
        }

        /*
         * Nothing to check for collisions, unlike restoring an account: the
         * unique index on (type, slug) is not partial, so a hidden work never
         * released its slug and nothing else can have taken it.
         */
        $work->setDeletedAt(null);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $names
     */
    private function replaceGenres(Work $work, array $names): void
    {
        $wanted = $this->genres->findOrCreateMany(array_map(strval(...), $names));

        foreach ($work->getGenres()->toArray() as $current) {
            if (!\in_array($current, $wanted, true)) {
                $work->removeGenre($current);
            }
        }
        foreach ($wanted as $genre) {
            $work->addGenre($genre);
        }
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException('invalid_date');
        }
    }

    /** Cleared inputs arrive as empty strings; the columns want null. */
    private function cleaned(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
