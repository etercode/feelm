<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpsertEntryRequest
{
    /**
     * @param array<string, mixed>|null $progress
     */
    public function __construct(
        #[Assert\Choice(choices: ['wishlist', 'active', 'done', 'dropped'], message: 'Invalid status.')]
        public ?string $status = null,

        #[Assert\Range(min: 0.5, max: 5)]
        public ?float $rating = null,

        public ?array $progress = null,

        /** When true with status null, clears the shelf row. */
        public bool $clear = false,
    ) {
    }
}
