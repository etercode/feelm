<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 180)]
        #[Assert\Regex(pattern: '/^[a-zA-Z0-9_]+$/', message: 'Username may only contain letters, numbers and underscores.')]
        public string $username = '',

        #[Assert\NotBlank]
        #[Assert\Email(message: 'That does not look like an email address.')]
        #[Assert\Length(max: 180)]
        public string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $password = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name = '',

        #[Assert\Length(max: 255)]
        public ?string $tagline = null,
    ) {
    }
}
