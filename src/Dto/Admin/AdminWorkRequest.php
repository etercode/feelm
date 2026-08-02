<?php

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Editing a catalogued work.
 *
 * Every field is optional: this is a PATCH, and a field left out keeps what the
 * crawler put there. Only what a person can sensibly correct is here — the
 * type and the slug are identity, external_score is written by a database
 * trigger, and popularity and vote counts are TMDB's numbers rather than
 * anybody's opinion.
 *
 * Editing is safe from the crawler, which is not obvious: CatalogWorkPersister
 * only ever writes a work on the pass that creates it, and both crawl commands
 * skip titles already stored. A correction made here stays corrected.
 */
readonly class AdminWorkRequest
{
    /**
     * @param list<string>|null $genres genre names, replacing whatever is set
     */
    public function __construct(
        #[Assert\Length(min: 1, max: 255)]
        public ?string $title = null,

        #[Assert\Length(max: 255)]
        public ?string $originalTitle = null,

        #[Assert\Range(min: 1800, max: 2200)]
        public ?int $year = null,

        #[Assert\Date(message: 'Use YYYY-MM-DD.')]
        public ?string $releaseDate = null,

        #[Assert\Length(max: 500)]
        public ?string $tagline = null,

        public ?string $overview = null,

        #[Assert\Length(max: 500)]
        public ?string $poster = null,

        #[Assert\Length(max: 500)]
        public ?string $backdrop = null,

        #[Assert\Range(min: 0, max: 100000)]
        public ?int $runtimeMinutes = null,

        #[Assert\Length(max: 16)]
        public ?string $certification = null,

        #[Assert\Length(max: 8)]
        public ?string $originalLanguage = null,

        #[Assert\Range(min: 0, max: 100000)]
        public ?int $pageCount = null,

        #[Assert\Length(max: 180)]
        public ?string $publisher = null,

        #[Assert\All([new Assert\Length(min: 1, max: 80)])]
        public ?array $genres = null,

        /*
         * IMDb's own units, 0 to 10 with one decimal, because that is the
         * number on the page you are copying it from. Sending it locks the
         * rating against the dataset import; imdbLocked: false is how you undo
         * that and hand the title back to IMDb.
         */
        #[Assert\Range(min: 0, max: 10)]
        public ?float $imdbRating = null,

        #[Assert\Range(min: 0, max: 100000000)]
        public ?int $imdbVotes = null,

        public ?bool $imdbLocked = null,
    ) {
    }
}
