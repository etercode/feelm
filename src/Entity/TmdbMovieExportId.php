<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Films.
 *
 * See TmdbExportRow for the shared shape and for why these are mapped at all.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'tmdb_movie_ids')]
class TmdbMovieExportId extends TmdbExportRow
{
    /** Only the film queue filters by year — see crawl-all --since. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $releaseYear = null;

    public function getReleaseYear(): ?int
    {
        return $this->releaseYear;
    }
}
