<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The editable half of a profile. Username is not here on purpose: handles
 * appear in URLs people have already shared, and changing one silently breaks
 * every link to it.
 *
 * Every field is sent on every save, so a null means "cleared", not "left
 * alone" — the form always posts the whole shape.
 */
readonly class UpdateProfileRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name = '',

        #[Assert\Length(max: 255)]
        public ?string $tagline = null,

        #[Assert\Length(max: 2000)]
        public ?string $bio = null,

        #[Assert\Length(max: 120)]
        public ?string $location = null,
    ) {
    }
}
