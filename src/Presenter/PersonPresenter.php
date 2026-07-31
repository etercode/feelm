<?php

namespace App\Presenter;

use App\Entity\Credit;
use App\Entity\Person;
use App\Service\PublicUrlGenerator;

/**
 * People and their credits.
 *
 * A service rather than a static class, like WorkPresenter and for the same
 * reason: photos are stored paths and the front end is on another origin, so
 * they have to go through PublicUrlGenerator.
 */
final class PersonPresenter
{
    public function __construct(
        private readonly PublicUrlGenerator $urls,
        private readonly WorkPresenter $works,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function one(Person $person, ?int $creditCount = null): array
    {
        return [
            'id' => $person->getId(),
            'slug' => $person->getSlug(),
            'name' => $person->getName(),
            'photo' => $this->urls->media($person->getPhoto()),
            'photoPath' => $person->getPhoto(),
            'externalId' => $person->getExternalId(),
            'creditCount' => $creditCount,
        ];
    }

    /**
     * One credit, from the work's side: who, as what.
     *
     * @return array<string, mixed>
     */
    public function credit(Credit $credit): array
    {
        $person = $credit->getPerson();

        return [
            'id' => $credit->getId(),
            'role' => $credit->getRole(),
            'character' => $credit->getCharacterName(),
            'position' => $credit->getPosition(),
            'person' => $person ? [
                'id' => $person->getId(),
                'slug' => $person->getSlug(),
                'name' => $person->getName(),
                'photo' => $this->urls->media($person->getPhoto()),
            ] : null,
        ];
    }

    /**
     * One credit, from the person's side: what they were on, as what.
     *
     * @return array<string, mixed>
     */
    public function creditOfWork(Credit $credit): array
    {
        $work = $credit->getWork();

        return [
            'id' => $credit->getId(),
            'role' => $credit->getRole(),
            'character' => $credit->getCharacterName(),
            'position' => $credit->getPosition(),
            'work' => $work ? [
                ...$this->works->compact($work),
                'hidden' => $work->isDeleted(),
            ] : null,
        ];
    }
}
