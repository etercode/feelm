<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A row of one of TMDB's daily id exports — the crawl queue.
 *
 * ---- two tables, nearly one shape ---------------------------------------------------
 *
 * Films and programmes are numbered in separate id spaces by TMDB and published
 * as separate files, so they are separate tables of identical shape. A mapped
 * superclass rather than inheritance: inheritance would ask Doctrine to put both
 * in one table keyed by a discriminator, which is precisely what these are not.
 * A superclass is only a description of columns, and each concrete class below
 * brings its own table — and its own extra column, because the two are not
 * quite the same: films carry the release year the queue filters on, and
 * programmes carry the reason one was skipped. That difference was invisible
 * for as long as neither table was mapped, which is the argument for mapping
 * them in miniature.
 *
 * ---- why declare any of it ---------------------------------------------------
 *
 * Nothing instantiates these. TmdbIdExport and TmdbSeriesExport read and write
 * both tables in raw SQL, because a 1.2 million row file is loaded in batches
 * and nothing about that wants an object.
 *
 * They are mapped because a table Doctrine has no mapping for is a table
 * Doctrine offers to drop — and it puts that offer in the same generated file as
 * whatever you were actually adding. These were hidden behind schema_filter for
 * a while, which stopped the offer by stopping the inspection, and took
 * doctrine:schema:validate's ability to notice a real mistake here with it.
 *
 * Three of the four indexes on each table cannot be expressed: two are partial
 * (WHERE crawled_at IS NULL — the queue is only ever asked what it has not done
 * yet) and one sorts DESC NULLS LAST. The migrations own those, and a diff will
 * keep offering to drop them. That noise is the accepted price of the tables
 * themselves being known: a dropped index is rebuilt from a migration, whereas
 * a dropped table is rows that are gone.
 */
#[ORM\MappedSuperclass]
abstract class TmdbExportRow
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    private int $tmdbId = 0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0])]
    private float $popularity = 0.0;

    /** Which day's export this row came from. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $exportedOn = null;

    /**
     * A hint, not the truth.
     *
     * It is reset whenever the export is re-downloaded, so the queue's real
     * test is an anti-join against external_ids — see TmdbIdExport::nextIds().
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $crawledAt = null;

    public function getTmdbId(): int
    {
        return $this->tmdbId;
    }

    public function getPopularity(): float
    {
        return $this->popularity;
    }

    public function getCrawledAt(): ?\DateTimeImmutable
    {
        return $this->crawledAt;
    }
}
