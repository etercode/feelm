<?php

namespace App\Dto;

use App\Entity\Feedback;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filing or editing a report.
 *
 * Both fields default to null rather than to a value, because this DTO serves
 * PATCH as well as POST: null means "not mentioned, leave it alone", which is
 * a different thing from an empty string. The controller supplies the defaults
 * a creation needs.
 */
readonly class FeedbackRequest
{
    public function __construct(
        #[Assert\Length(min: 3, max: 5000)]
        public ?string $body = null,

        #[Assert\Choice(choices: Feedback::CATEGORIES)]
        public ?string $category = null,
    ) {
    }
}
