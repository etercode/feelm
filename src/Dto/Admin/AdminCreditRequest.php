<?php

namespace App\Dto\Admin;

use App\Entity\Credit;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adding or changing who is credited on a work.
 *
 * The person arrives as a name rather than an id, because that is how the
 * crawler creates them and how somebody fixing a cast list thinks. An unknown
 * name makes a new person; a known one is reused.
 *
 * `character` only means anything for cast. The service blanks it for crew,
 * which the unique index over (work, person, role, character_name) depends on.
 */
readonly class AdminCreditRequest
{
    public function __construct(
        #[Assert\Length(min: 1, max: 180)]
        public ?string $person = null,

        #[Assert\Choice(choices: Credit::ROLES, message: 'Unknown credit role.')]
        public ?string $role = null,

        #[Assert\Length(max: 255)]
        public ?string $character = null,

        #[Assert\Range(min: 0, max: 32000)]
        public ?int $position = null,
    ) {
    }
}
