<?php

namespace App\Entity;

use App\Repository\UserIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A way somebody signs in that is not a password — one row per linked provider.
 *
 * The provider's own id is the key, never the email address. Google's `sub`
 * claim never changes; the address on the account can, and matching on it would
 * quietly hand somebody else's account over the day an address is reassigned.
 *
 * A table rather than a column on users, because the second provider costs
 * nothing this way and a migration the other.
 */
#[ORM\Entity(repositoryClass: UserIdentityRepository::class)]
#[ORM\Table(name: 'user_identities')]
#[ORM\UniqueConstraint(name: 'uniq_identity_provider_subject', columns: ['provider', 'provider_id'])]
#[ORM\Index(name: 'idx_identity_user', columns: ['user_id'])]
class UserIdentity
{
    public const PROVIDER_GOOGLE = 'google';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 32)]
    private string $provider;

    /** The provider's stable id for this person — Google's `sub`. */
    #[ORM\Column(length: 191)]
    private string $providerId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $provider, string $providerId)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->providerId = $providerId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
