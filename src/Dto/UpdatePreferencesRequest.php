<?php

namespace App\Dto;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How somebody wants the site rendered, as opposed to what it says about them.
 *
 * Separate from UpdateProfileRequest because the two are saved by different
 * controls at different moments: picking a language is one click on a dropdown
 * and should not require a display name to come with it, which posting the
 * profile shape would.
 *
 * Both fields are required — this endpoint exists to set them, so an omission
 * is a bug in the caller rather than a request to leave them alone.
 */
readonly class UpdatePreferencesRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: User::SUPPORTED_LOCALES)]
        public string $locale = User::DEFAULT_LOCALE,

        /**
         * Assert\Timezone accepts any name in the running PHP's database, which
         * is the same question User::setTimezone() asks. Validating here as
         * well is what turns an unknown zone into a 422 the settings form can
         * show, instead of a silent fallback to UTC the person never sees.
         */
        #[Assert\NotBlank]
        #[Assert\Timezone]
        public string $timezone = User::DEFAULT_TIMEZONE,
    ) {
    }
}
