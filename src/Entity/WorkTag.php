<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A country, keyword or studio on a title.
 *
 * ---- an entity that nothing hydrates ----------------------------------------
 *
 * Nothing reads or writes this class. WorkDetailsWriter fills the table with
 * batched raw SQL — a title carries about thirteen of these, and doing it one
 * managed object at a time is what made the first backfill run at 3/s instead
 * of 15 — and the read side goes through WorkSearch, which joins it in SQL.
 *
 * It exists because a mapping is a description of the schema, and Doctrine
 * treats a table it has no description of as a stray to be dropped. Leaving it
 * undeclared meant every `doctrine:migrations:diff` emitted DROP TABLE work_tag
 * next to whatever was actually being generated. It was excluded via
 * schema_filter for a while, which silenced the symptom by telling Doctrine to
 * stop looking — and the price of that is `doctrine:schema:validate` no longer
 * checking this table at all.
 *
 * Declaring it costs nothing at run time. An entity is only expensive when
 * something instantiates one, and nothing here ever will.
 *
 * ---- kinds -------------------------------------------------------------------
 *
 * The values are small integers rather than a string per row, and live on
 * WorkDetailsWriter, which is the only thing that writes them.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'work_tag')]
#[ORM\Index(name: 'idx_work_tag_lookup', columns: ['kind', 'value', 'work_id'])]
class WorkTag
{
    /**
     * Part of the key, not a column beside it — a title cannot carry the same
     * keyword twice, and the primary key is what says so.
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Work::class)]
    #[ORM\JoinColumn(name: 'work_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Work $work = null;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $kind = 0;

    #[ORM\Id]
    #[ORM\Column(length: 120)]
    private string $value = '';

    public function getWork(): ?Work
    {
        return $this->work;
    }

    public function getKind(): int
    {
        return $this->kind;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
