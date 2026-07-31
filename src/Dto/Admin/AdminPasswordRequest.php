<?php

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Setting somebody else's password.
 *
 * Deliberately does not ask for the current one. An administrator does not know
 * it, and the point of the endpoint is getting a locked-out person back in;
 * changing your own password still goes through /api/me/password, which does
 * ask.
 */
readonly class AdminPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $password = '',
    ) {
    }
}
