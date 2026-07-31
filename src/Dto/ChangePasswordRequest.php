<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The current password is required even though the caller is already
 * authenticated: an access token that leaks should not be enough to lock the
 * owner out of their own account.
 */
readonly class ChangePasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $currentPassword = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $newPassword = '',
    ) {
    }
}
