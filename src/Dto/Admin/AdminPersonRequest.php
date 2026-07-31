<?php

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Correcting a person.
 *
 * The slug is not here: it follows the name, because the crawler looks people
 * up by it and a corrected name left on the old slug is how the next crawl
 * creates a second row for the same person.
 */
readonly class AdminPersonRequest
{
    public function __construct(
        #[Assert\Length(min: 1, max: 180)]
        public ?string $name = null,

        #[Assert\Length(max: 500)]
        public ?string $photo = null,

        #[Assert\Length(max: 64)]
        public ?string $externalId = null,
    ) {
    }
}
