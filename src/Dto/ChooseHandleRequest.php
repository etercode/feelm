<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The one chance a Google-created account gets to change the handle it was
 * given. Same rules as sign-up, because it ends up in the same URLs.
 */
readonly class ChooseHandleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 180)]
        #[Assert\Regex(
            pattern: '/^[a-zA-Z0-9_]+$/',
            message: 'Username may only contain letters, numbers and underscores.',
        )]
        public string $username = '',
    ) {
    }
}
