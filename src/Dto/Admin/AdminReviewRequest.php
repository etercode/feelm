<?php

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A moderator editing somebody else's review.
 *
 * Both fields are optional so a moderator can trim the text without touching
 * the score, or the other way round — the controller fills in whatever was
 * left out from what the review already says. The range is the same one the
 * star control can express, because the same value ends up in the same column.
 */
readonly class AdminReviewRequest
{
    public function __construct(
        #[Assert\Range(min: 0.5, max: 5)]
        public ?float $rating = null,

        #[Assert\Length(max: 20000)]
        public ?string $body = null,
    ) {
    }
}
