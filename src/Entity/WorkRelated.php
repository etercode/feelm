<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What TMDB says is like this title.
 *
 * Declared for the same reason as WorkTag and used the same way: nothing
 * hydrates it. WorkDetailsWriter writes it in one batched statement per title
 * — twenty-four rows each — and the read side resolves the pointers in SQL.
 *
 * `tmdbId` is deliberately their id and not ours. The title being pointed at
 * may not have been crawled yet, and an id keeps the pointer good for whenever
 * it arrives; a foreign key to works could not be satisfied and would have to
 * be written later or not at all. The read side joins through external_ids and
 * simply shows fewer.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'work_related')]
#[ORM\Index(name: 'idx_work_related_lookup', columns: ['work_id', 'kind', 'position'])]
class WorkRelated
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Work::class)]
    #[ORM\JoinColumn(name: 'work_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Work $work = null;

    /** 'similar' or 'recommended' — TMDB offers both and they disagree usefully. */
    #[ORM\Id]
    #[ORM\Column(length: 12)]
    private string $kind = '';

    #[ORM\Id]
    #[ORM\Column]
    private int $tmdbId = 0;

    /** TMDB's own ordering, which is the ranking and worth keeping. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    public function getWork(): ?Work
    {
        return $this->work;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTmdbId(): int
    {
        return $this->tmdbId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
