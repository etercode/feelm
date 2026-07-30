<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SaveReviewRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: 0.5, max: 5)]
        public float $rating = 0,

        #[Assert\NotBlank]
        #[Assert\Length(max: 20000)]
        public string $body = '',
    ) {
    }
}
