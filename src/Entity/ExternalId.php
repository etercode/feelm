<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The id a source gave a work — "tmdb/27205". A unique constraint on
 * (source, external_id) is what makes a re-crawl update a row instead of
 * inserting a duplicate; matching on a source URL string never could.
 */
#[ORM\Entity]
#[ORM\Table(name: 'external_ids')]
#[ORM\UniqueConstraint(name: 'uniq_external_id', columns: ['source', 'external_id'])]
#[ORM\Index(name: 'idx_external_id_work', columns: ['work_id'])]
class ExternalId
{
    public const SOURCE_TMDB = 'tmdb';

    /**
     * TMDB numbers films and television separately, so movie 1396 and series
     * 1396 are two unrelated titles. 78,646 of the 228,142 ids in the series
     * export collide with a movie already in the catalog — under one shared
     * source the unique constraint above would reject every one of them, and
     * the persister, which finds the row to update by source and id, would
     * have overwritten those films with television shows.
     *
     * Keeping television under its own source is what makes the two id spaces
     * separate. Movies keep `tmdb`, so nothing already stored has to move.
     * Callers that only want "the TMDB id" should use {@see tmdbFor()}.
     */
    public const SOURCE_TMDB_TV = 'tmdb_tv';

    public const SOURCE_IMDB = 'imdb';
    public const SOURCE_STEAM = 'steam';
    public const SOURCE_OPENLIBRARY = 'openlibrary';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Work::class, inversedBy: 'externalIds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Work $work = null;

    #[ORM\Column(length: 24)]
    private ?string $source = null;

    #[ORM\Column(name: 'external_id', length: 64)]
    private ?string $externalId = null;

    public function __construct(?string $source = null, ?string $externalId = null)
    {
        $this->source = $source;
        $this->externalId = $externalId;
    }

    /** Which TMDB id space a work of this type belongs to. */
    public static function tmdbFor(?string $type): string
    {
        return 'series' === $type ? self::SOURCE_TMDB_TV : self::SOURCE_TMDB;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWork(): ?Work
    {
        return $this->work;
    }

    public function setWork(?Work $work): static
    {
        $this->work = $work;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }
}
