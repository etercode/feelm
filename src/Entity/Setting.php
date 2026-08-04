<?php

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One configurable value, edited from the admin rather than deployed.
 *
 * Everything the application needs to run still comes from the environment;
 * this is for the handful of things an administrator changes without wanting a
 * deploy — which right now is where notifications go and which of them to send.
 *
 * The name is the primary key. There is nothing else a row is identified by, a
 * surrogate id would only be something to look up the name with, and a natural
 * key means `set()` can be an upsert rather than a find-then-branch.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'settings')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    /**
     * Null and the empty string are different: null is "never set", '' is "set
     * to nothing on purpose". Clearing a bot token has to be able to say the
     * second one.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, ?string $value = null)
    {
        $this->name = $name;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
