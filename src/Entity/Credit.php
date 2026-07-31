<?php

namespace App\Entity;

use App\Repository\CreditRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person's involvement in a work. The role tells you what kind — cast
 * members carry a character, crew do not — and `position` keeps the source
 * ordering so a cast list still reads top-billed first.
 */
#[ORM\Entity(repositoryClass: CreditRepository::class)]
#[ORM\Table(name: 'credits')]
#[ORM\Index(name: 'idx_credit_work_role', columns: ['work_id', 'role', 'position'])]
#[ORM\Index(name: 'idx_credit_person', columns: ['person_id', 'role'])]
#[ORM\UniqueConstraint(name: 'uniq_credit_work_person_role_character', columns: ['work_id', 'person_id', 'role', 'character_name'])]
class Credit
{
    public const ROLE_CAST = 'cast';
    public const ROLE_DIRECTOR = 'director';
    public const ROLE_WRITER = 'writer';
    public const ROLE_CREATOR = 'creator';
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_PUBLISHER = 'publisher';
    public const ROLE_AUTHOR = 'author';

    public const ROLES = [
        self::ROLE_CAST,
        self::ROLE_DIRECTOR,
        self::ROLE_WRITER,
        self::ROLE_CREATOR,
        self::ROLE_DEVELOPER,
        self::ROLE_PUBLISHER,
        self::ROLE_AUTHOR,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Work::class, inversedBy: 'credits')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Work $work = null;

    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'credits')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Person $person = null;

    #[ORM\Column(length: 24)]
    private ?string $role = null;

    /**
     * The character played, or '' for crew. Empty rather than null so the
     * unique index below can actually prevent duplicate credits — Postgres
     * treats two nulls as different values.
     */
    #[ORM\Column(name: 'character_name', length: 255, options: ['default' => ''])]
    private string $characterName = '';

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

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

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(Person $person): static
    {
        $this->person = $person;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        if (!\in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown credit role "%s".', $role));
        }
        $this->role = $role;

        return $this;
    }

    public function getCharacterName(): ?string
    {
        return '' === $this->characterName ? null : $this->characterName;
    }

    public function setCharacterName(?string $characterName): static
    {
        $this->characterName = $characterName ?? '';

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
