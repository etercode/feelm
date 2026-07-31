<?php

namespace App\Service\Admin;

use App\Entity\Credit;
use App\Entity\Person;
use App\Entity\Work;
use App\Repository\CreditRepository;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * People, and who is credited on what.
 *
 * The merge is the reason this exists. The crawler identifies a person by a
 * slug made from their name, so every spelling variant TMDB has ever sent
 * becomes a separate row — the same actor as "Bong Joon-ho", "Bong Joon Ho"
 * and "봉준호" is three people, each holding part of their filmography.
 * Nothing but a person looking at them can tell that they are one.
 *
 * Deleting is a real delete: people carry nothing of anybody's, unlike a work.
 * It is refused while credits still point at the row, because that is almost
 * always a merge waiting to happen rather than a deletion.
 */
final class PeopleAdmin
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonRepository $people,
        private readonly CreditRepository $credits,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function update(Person $person, ?string $name, ?string $photo, ?string $externalId): Person
    {
        if (null !== $name) {
            $name = trim($name);
            if ('' === $name) {
                throw new \InvalidArgumentException('name_required');
            }

            /*
             * The slug follows the name, because it is derived from it and the
             * crawler will look the person up by it next time. Leaving a
             * corrected name on the old slug means the next crawl does not
             * recognise them and makes a second row — exactly the duplicate
             * this class exists to clean up.
             */
            if ($name !== $person->getName()) {
                $person->setName(mb_substr($name, 0, 180));
                $person->setSlug($this->freeSlug($name, $person));
            }
        }

        if (null !== $photo) {
            $photo = trim($photo);
            $person->setPhoto('' === $photo ? null : mb_substr($photo, 0, 500));
        }

        if (null !== $externalId) {
            $externalId = trim($externalId);
            $person->setExternalId('' === $externalId ? null : mb_substr($externalId, 0, 64));
        }

        $this->entityManager->flush();

        return $person;
    }

    /**
     * Folds one person into another: every credit is repointed, then the
     * emptied row goes.
     *
     * @throws \InvalidArgumentException
     */
    public function merge(Person $loser, Person $winner): int
    {
        if ($loser->getId() === $winner->getId()) {
            throw new \InvalidArgumentException('cannot_merge_into_self');
        }

        $loserId = (int) $loser->getId();
        $winnerId = (int) $winner->getId();

        /*
         * Anything the loser knew that the winner did not — and flushed here,
         * before anything else touches the entity manager. Doctrine 3's
         * clear() takes no arguments and empties the whole thing; PHP ignores
         * the extra one silently, so a clear() further down would detach the
         * winner and throw these away without a word.
         */
        if (null === $winner->getPhoto() && null !== $loser->getPhoto()) {
            $winner->setPhoto($loser->getPhoto());
        }
        if (null === $winner->getExternalId() && null !== $loser->getExternalId()) {
            $winner->setExternalId($loser->getExternalId());
        }
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();

        /*
         * All three statements in one transaction: a failure between them
         * would leave a person half-merged, with some credits moved and a row
         * that still exists.
         *
         * The delete comes first because a straight UPDATE would trip
         * uniq(work_id, person_id, role, character_name) wherever both are
         * credited on the same work in the same role — which is the common
         * case, since a duplicate person usually comes from one film being
         * crawled under two spellings of the name. Those credits are dropped
         * rather than moved: the winner already has one.
         */
        $moved = $connection->transactional(static function ($tx) use ($loserId, $winnerId): int {
            $dropped = (int) $tx->executeStatement(
                'DELETE FROM credits loser
                 WHERE loser.person_id = :loser
                   AND EXISTS (
                       SELECT 1 FROM credits kept
                       WHERE kept.person_id = :winner
                         AND kept.work_id = loser.work_id
                         AND kept.role = loser.role
                         AND kept.character_name = loser.character_name
                   )',
                ['loser' => $loserId, 'winner' => $winnerId],
            );

            $repointed = (int) $tx->executeStatement(
                'UPDATE credits SET person_id = :winner WHERE person_id = :loser',
                ['winner' => $winnerId, 'loser' => $loserId],
            );

            $tx->executeStatement('DELETE FROM people WHERE id = :loser', ['loser' => $loserId]);

            return $dropped + $repointed;
        });

        /*
         * Every Credit and Person the entity manager is holding is now a lie —
         * rows moved, rows vanished, and one person no longer exists. Emptying
         * it is the only honest thing to do, and it is why the winner's own
         * changes were flushed before any of this started.
         */
        $this->entityManager->clear();

        return $moved;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function delete(Person $person, bool $force = false): void
    {
        $count = $this->credits->count(['person' => $person]);
        if ($count > 0 && !$force) {
            throw new \InvalidArgumentException('person_has_credits');
        }

        $this->entityManager->remove($person);
        $this->entityManager->flush();
    }

    /* ------------------------------------------------------------- credits */

    /**
     * @throws \InvalidArgumentException
     */
    public function addCredit(Work $work, string $personName, string $role, ?string $character): Credit
    {
        $personName = trim($personName);
        if ('' === $personName) {
            throw new \InvalidArgumentException('person_required');
        }

        $person = $this->people->findOrCreate($personName);

        $credit = (new Credit())
            ->setWork($work)
            ->setPerson($person)
            ->setCharacterName($this->character($character, $role))
            ->setPosition($this->credits->nextPosition($work, $role));

        // setRole throws on anything not in Credit::ROLES.
        $credit->setRole($role);

        $this->entityManager->persist($credit);
        $this->flushUnique();

        return $credit;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function updateCredit(Credit $credit, ?string $role, ?string $character, ?int $position): Credit
    {
        if (null !== $role) {
            $credit->setRole($role);
        }

        if (null !== $character) {
            $credit->setCharacterName($this->character($character, $credit->getRole() ?? ''));
        }

        if (null !== $position) {
            $credit->setPosition(max(0, $position));
        }

        $this->flushUnique();

        return $credit;
    }

    public function removeCredit(Credit $credit): void
    {
        $this->entityManager->remove($credit);
        $this->entityManager->flush();
    }

    /* ------------------------------------------------------------- private */

    /** Only cast carry a character; crew rows must stay '' for the unique index. */
    private function character(?string $character, string $role): string
    {
        if (Credit::ROLE_CAST !== $role) {
            return '';
        }

        return mb_substr(trim((string) $character), 0, 255);
    }

    /**
     * A slug nobody else is using. The crawler's own slugFor() is private, so
     * this mirrors the same rule — slugify, truncated to the column width.
     */
    private function freeSlug(string $name, Person $person): string
    {
        $base = mb_substr(PersonRepository::slugify($name), 0, 200);
        $slug = $base;
        $n = 2;

        while (true) {
            $existing = $this->people->findOneBy(['slug' => $slug]);
            if (null === $existing || $existing->getId() === $person->getId()) {
                return $slug;
            }
            $suffix = '-'.$n++;
            $slug = mb_substr($base, 0, 200 - mb_strlen($suffix)).$suffix;
        }
    }

    /**
     * A duplicate credit is a conflict, not a crash.
     *
     * @throws \InvalidArgumentException
     */
    private function flushUnique(): void
    {
        try {
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            throw new \InvalidArgumentException('duplicate_credit');
        }
    }
}
