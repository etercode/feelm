<?php

namespace App\Entity;

use App\Repository\DeviceTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One installation of the app that can be pushed to.
 *
 * ---- why the token is the natural key ---------------------------------------
 *
 * FCM issues a registration token per app install, not per user. Two people
 * sharing a phone produce one token that has to follow whoever is signed in, so
 * registering an existing token re-points the row rather than inserting a
 * second one — otherwise the previous owner keeps receiving the new owner's
 * episodes. The unique index is what makes that an upsert instead of a race.
 *
 * ---- why locale is stored per device ----------------------------------------
 *
 * A person can read Feelm in Azerbaijani on their phone and have picked English
 * in their account preferences, because the phone's per-app language is set in
 * Android's settings and the account's is set on the website. The notification
 * has to match the phone it lands on, so the device's own locale wins and the
 * account locale is only the fallback for devices that never reported one.
 */
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: 'device_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_device_token', columns: ['token'])]
#[ORM\Index(name: 'idx_device_token_user', columns: ['user_id'])]
// Declared even though only the pruner uses it: an index that exists in the
// database and not in the mapping is one schema:update offers to drop every
// time somebody runs it.
#[ORM\Index(name: 'idx_device_token_stale', columns: ['last_seen_at'])]
class DeviceToken
{
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_WEB = 'web';

    public const PLATFORMS = [
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
        self::PLATFORM_WEB,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Deliberately TEXT rather than a sized column. Google documents no maximum
     * for a registration token and has lengthened them before; a VARCHAR(255)
     * that silently truncates would produce tokens that look valid and are not.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $token;

    #[ORM\Column(length: 16)]
    private string $platform = self::PLATFORM_ANDROID;

    /** The app's UI language on this device, not the account's. */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Refreshed every time the app hands the token back, which it does on each
     * cold start. FCM does not tell you a device was wiped — it only stops
     * accepting the token — so a token nothing has touched in months is the
     * only signal that an install is gone.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(?string $platform): static
    {
        $platform = strtolower(trim((string) $platform));
        $this->platform = \in_array($platform, self::PLATFORMS, true)
            ? $platform
            : self::PLATFORM_ANDROID;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $locale = strtolower(trim((string) $locale));
        $this->locale = '' === $locale ? null : substr($locale, 0, 5);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(): static
    {
        $this->lastSeenAt = new \DateTimeImmutable();

        return $this;
    }
}
