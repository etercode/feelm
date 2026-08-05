<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Programmes. Separate from the films table because TMDB numbers them
 * separately, and looking one up in the other finds an unrelated title.
 *
 * See TmdbExportRow for the shared shape and for why these are mapped at all.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'tmdb_series_ids')]
class TmdbSeriesExportId extends TmdbExportRow
{
    /**
     * Why a programme was passed over — so a second pass does not spend a
     * request rediscovering that it is, say, older than the --since floor.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $skippedReason = null;

    public function getSkippedReason(): ?string
    {
        return $this->skippedReason;
    }
}
