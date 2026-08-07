<?php

namespace App\Service\Admin;

use App\Dto\Admin\AdminWorkRequest;
use App\Entity\Work;
use App\Entity\WorkRating;
use App\Repository\GenreRepository;
use App\Repository\WorkRepository;
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
    /** What bulk() accepts. Mirrored in the client so the two cannot drift. */
    public const BULK_ACTIONS = ['adult', 'not_adult', 'delete', 'restore'];

    /**
     * How many titles one call may carry.
     *
     * A filmography is the unit here and the longest run to a few hundred, so
     * this is headroom rather than a limit anybody meets. It exists because the
     * ids arrive from a browser and `findBy(['id' => $ids])` would otherwise
     * hand Postgres an unbounded IN list.
     */
    public const BULK_LIMIT = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GenreRepository $genres,
        private readonly WorkRepository $works,
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
     * The same four actions over a list of ids.
     *
     * Built for the way the work is actually done: open an actor whose entire
     * filmography is the problem, select the lot, act once. A hundred separate
     * requests to hide a hundred titles is a hundred chances for one to be lost
     * halfway, and no way to tell which.
     *
     * A title already in the state being asked for is skipped rather than
     * refused. Over a filmography that case is the norm — the selection is
     * "everything on this page", and some of it was dealt with last week — so
     * treating it as an error would make the common path the failing one.
     *
     * @param list<int> $ids
     *
     * @return array{changed: int, skipped: int}
     *
     * @throws \InvalidArgumentException on an unknown action
     */
    public function bulk(array $ids, string $action): array
    {
        if (!\in_array($action, self::BULK_ACTIONS, true)) {
            throw new \InvalidArgumentException('unknown_action');
        }

        $changed = 0;
        $skipped = 0;

        foreach ($this->works->findBy(['id' => $ids]) as $work) {
            $done = match ($action) {
                'adult' => $this->applyAdult($work, true),
                'not_adult' => $this->applyAdult($work, false),
                'delete' => $this->applyHidden($work, true),
                'restore' => $this->applyHidden($work, false),
            };

            $done ? ++$changed : ++$skipped;
        }

        // One flush for the whole batch rather than one per title: the point of
        // this endpoint is that it is a single unit of work.
        $this->entityManager->flush();

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * ---- why flagging as adult also sets deletedAt --------------------------
     *
     * Hiding is `deleted_at`, and it stays that way. Every read path in the
     * application already checks that column — WorkSearch's hand-written SQL, a
     * dozen queries in WorkRepository, the browse facets, the rails — and
     * introducing a second thing they would all have to check as well is
     * introducing a place where one of them forgets. That is not a theoretical
     * worry: a title would fail *open* on the query that was missed, staying
     * visible, which for this flag means showing the artwork we are hiding it
     * for.
     *
     * So `adult` records *why* a row is hidden rather than doing the hiding.
     * The two move together, and the difference between a title hidden for
     * being 18+ and one hidden for being a duplicate is a column the moderation
     * screens read and nothing else has to.
     *
     * Should adult titles ever go behind a viewer's own setting, the read paths
     * change from `deleted_at IS NULL` to something that admits them back —
     * the same work as gating on a separate column, and none of it made harder
     * by this.
     *
     * @return bool whether anything actually changed
     */
    private function applyAdult(Work $work, bool $adult): bool
    {
        if ($work->isAdult() === $adult) {
            return false;
        }

        $work->setAdult($adult);
        $work->setDeletedAt($adult ? new \DateTimeImmutable() : null);

        return true;
    }

    /** @return bool whether anything actually changed */
    private function applyHidden(Work $work, bool $hidden): bool
    {
        if ($work->isDeleted() === $hidden) {
            return false;
        }

        if ($hidden) {
            $work->softDelete();
        } else {
            $work->setDeletedAt(null);
            $work->setAdult(false);
        }

        return true;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function restore(Work $work): void
    {
        if (!$work->isDeleted()) {
            throw new \InvalidArgumentException('not_deleted');
        }

        // Un-hiding a title that was hidden *for* being 18+ has to clear the
        // reason as well, or it comes back flagged and visible at once.
        $work->setAdult(false);

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
