<?php

namespace App\Service;

use App\Entity\Entry;
use App\Entity\Work;
use App\Entity\User;
use App\Repository\EntryRepository;
use Doctrine\ORM\EntityManagerInterface;

class ShelfService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
    ) {
    }

    /**
     * @param array{status?: string|null, rating?: float|null, progress?: array<string, mixed>|null} $data
     */
    public function upsert(User $user, Work $work, array $data): ?Entry
    {
        // Null status clears the shelf row entirely.
        if (\array_key_exists('status', $data) && null === $data['status']) {
            $existing = $this->entryRepository->findOneByUserAndWork($user, $work);
            if (null !== $existing) {
                $this->entityManager->remove($existing);
                $this->entityManager->flush();
            }

            return null;
        }

        $entry = $this->entryRepository->findOneByUserAndWork($user, $work);

        if (null === $entry) {
            /*
             * Claim the row in one statement rather than SELECT-then-INSERT.
             *
             * Two requests for the same title arriving together — a double
             * click, or the client sending a status and a rating at once — both
             * saw no row and both inserted one, and the second died on
             * uniq_entry_user_work. Production reported it as
             * UniqueConstraintViolationException.
             *
             * Catching the violation instead would mean recovering from a
             * closed EntityManager, which is a much worse thing to have to get
             * right. ON CONFLICT DO NOTHING makes the loser of the race a no-op
             * and leaves both requests holding a row that exists; whichever
             * lands second then updates it, which is what the caller asked for
             * either way.
             */
            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO entries (user_id, work_id, status, updated_at)
                 VALUES (:user, :work, :status, NOW())
                 ON CONFLICT (user_id, work_id) DO NOTHING',
                [
                    'user' => $user->getId(),
                    'work' => $work->getId(),
                    'status' => $data['status'] ?? 'wishlist',
                ],
            );

            $entry = $this->entryRepository->findOneByUserAndWork($user, $work);
        }

        if (null === $entry) {
            // The insert was a no-op and the row still is not there, which can
            // only mean it was removed between the two statements.
            throw new \InvalidArgumentException('entry_vanished');
        }

        if (isset($data['status'])) {
            if (!\in_array($data['status'], Entry::STATUSES, true)) {
                throw new \InvalidArgumentException('invalid_status');
            }
            $entry->setStatus($data['status']);
        }

        if (\array_key_exists('rating', $data)) {
            $rating = $data['rating'];
            if (null !== $rating && !$this->isValidRating((float) $rating)) {
                throw new \InvalidArgumentException('invalid_rating');
            }
            $entry->setRating(null === $rating ? null : (float) $rating);
        }

        if (\array_key_exists('progress', $data)) {
            $entry->setProgress($this->normalizeProgress($work, $data['progress']));
        }

        $this->entityManager->flush();

        return $entry;
    }

    private function isValidRating(float $rating): bool
    {
        if ($rating < 0.5 || $rating > 5.0) {
            return false;
        }

        // Half-star steps only.
        return abs($rating * 2 - round($rating * 2)) < 0.001;
    }

    /**
     * @param array<string, mixed>|null $progress
     *
     * @return array<string, mixed>|null
     */
    private function normalizeProgress(Work $work, ?array $progress): ?array
    {
        if (null === $progress) {
            return null;
        }

        return match ($work->getType()) {
            'series' => [
                'season' => (int) ($progress['season'] ?? 1),
                'episode' => (int) ($progress['episode'] ?? 1),
            ],
            'game' => [
                'hours' => (float) ($progress['hours'] ?? 0),
            ],
            'book' => [
                'page' => (int) ($progress['page'] ?? 1),
            ],
            default => null,
        };
    }
}
