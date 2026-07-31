<?php

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creating or editing an account from the admin.
 *
 * Every field is optional so the same shape serves POST and PATCH: on a create
 * the controller insists on username, name and password itself, and on a patch
 * null means "leave it alone". That is why there is no NotBlank here — a
 * missing field and an empty one have to stay distinguishable.
 */
readonly class AdminUserRequest
{
    /**
     * @param list<string>|null $roles
     */
    public function __construct(
        #[Assert\Length(min: 3, max: 180)]
        #[Assert\Regex(pattern: '/^[a-zA-Z0-9_]+$/', message: 'Username may only contain letters, numbers and underscores.')]
        public ?string $username = null,

        #[Assert\Email(message: 'That does not look like an email address.')]
        #[Assert\Length(max: 180)]
        public ?string $email = null,

        public ?bool $emailVerified = null,

        #[Assert\Length(max: 100)]
        public ?string $name = null,

        #[Assert\Length(max: 255)]
        public ?string $tagline = null,

        public ?string $bio = null,

        #[Assert\Length(max: 120)]
        public ?string $location = null,

        #[Assert\All([new Assert\Choice(choices: ['ROLE_MODERATOR', 'ROLE_ADMIN'], message: 'Unknown role.')])]
        public ?array $roles = null,

        /** Only read when creating; changing one afterwards is its own endpoint. */
        #[Assert\Length(min: 8, max: 4096)]
        public ?string $password = null,
    ) {
    }
}
