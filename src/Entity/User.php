<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

// "user" is reserved in PostgreSQL, so the table is named "users".
// Uniqueness is partial (only among non-soft-deleted rows).
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'], options: ['where' => '(deleted_at IS NULL)'])]
// Parenthesised exactly as Postgres stores it, or schema:validate reports a
// difference on every run and a diff would keep proposing to rebuild it.
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'], options: ['where' => '((deleted_at IS NULL) AND (email IS NOT NULL))'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    /**
     * Everybody has this one; getRoles() appends it rather than storing it, so
     * it never appears in the json column and cannot be taken away.
     */
    public const ROLE_USER = 'ROLE_USER';

    /** Moderates what people write: reviews today, more later. */
    public const ROLE_MODERATOR = 'ROLE_MODERATOR';

    /** Everything, including who else gets a role. */
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    /**
     * What an administrator may hand out. ROLE_USER is deliberately absent —
     * granting it would be a no-op and revoking it would look like it did
     * something.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = [self::ROLE_MODERATOR, self::ROLE_ADMIN];

    public const DEFAULT_LOCALE = 'en';

    public const DEFAULT_TIMEZONE = 'UTC';

    /**
     * The languages the site has a dictionary for.
     *
     * This list is the contract between the two halves of the application: the
     * front end ships one message file per entry and the API refuses anything
     * that is not one. Adding a language means adding it in both places, and
     * the order here is the order the settings dropdown offers them in.
     *
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['en', 'az', 'tr', 'ru'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $username = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /**
     * Null for accounts that only ever sign in through Google. Those cannot log
     * in with a password until they set one, which is what UserChecker and the
     * "set a password" branch of /api/me/password exist for.
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    /**
     * Unique among live accounts, like the username. Nullable because accounts
     * that predate sign-up asking for one still exist; every new account has
     * one.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /**
     * Whether somebody has proven they own the address. Only Google sets this
     * true today — a password sign-up is taken on trust until there is a way to
     * send mail, which is why an unverified address never links an account.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $emailVerified = false;

    /**
     * Set on accounts created through Google, which arrive without a username.
     * They get a generated one and a single chance to change it, because the
     * handle is in every link to their profile from then on.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $handlePending = false;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    /**
     * Which language to render the site in, as a bare ISO 639-1 code.
     *
     * Not nullable and not "whatever the browser asked for": the browser header
     * is a decent first guess and the front end uses it as one, but once
     * somebody has picked, that pick has to travel with the account rather than
     * with the device. Anything outside SUPPORTED_LOCALES never reaches the
     * column — the setter is the gate, because a stray value here would be a
     * missing dictionary at render time.
     */
    #[ORM\Column(length: 5, options: ['default' => self::DEFAULT_LOCALE])]
    private string $locale = self::DEFAULT_LOCALE;

    /**
     * An IANA zone name — "Asia/Baku", not "+04:00".
     *
     * Offsets go stale twice a year; names do not. Everything stored is UTC, so
     * this is purely a display instruction, which is why it lives next to the
     * language rather than anywhere near the timestamps themselves.
     */
    #[ORM\Column(length: 64, options: ['default' => self::DEFAULT_TIMEZONE])]
    private string $timezone = self::DEFAULT_TIMEZONE;

    /**
     * Public path to the uploaded portrait, e.g. "/media/avatars/7-a1b2c3.jpg".
     * Null means the front end draws initials instead, which most accounts
     * never move on from.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    /**
     * When this account last caught up with the catalog. Anything the crawler
     * added after this — and that the person has not opened — reads as new.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $seenUpTo = null;

    /**
     * Which pushes this account wants, as {kind: bool}.
     *
     * Absent means yes, exactly as the Telegram event toggles work: a person who
     * has never opened notification settings should still hear that a series
     * they are watching aired tonight. Only an explicit false silences a kind,
     * so adding a new kind later switches it on for everybody rather than
     * shipping dark to the entire user base.
     *
     * Not a column per kind: the set changes as features land, and four
     * migrations to add four booleans is four deploys to answer a product
     * question.
     *
     * @var array<string, bool>
     */
    // jsonb, not json: Doctrine's json type maps to plain JSON on Postgres, and
    // the column was created as JSONB. Without this the mapping and the database
    // disagree forever and schema:validate never comes back clean.
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}', 'jsonb' => true])]
    private array $pushPrefs = [];

    /**
     * When the morning digest last went to this account.
     *
     * The guard that makes app:push:digest safe to run twice. Without it the
     * command is only correct while cron is, and "somebody ran it by hand"
     * becomes a duplicate notification for everybody in that timezone.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pushDigestAt = null;

    public function getPushDigestAt(): ?\DateTimeImmutable
    {
        return $this->pushDigestAt;
    }

    public function setPushDigestAt(?\DateTimeImmutable $at): static
    {
        $this->pushDigestAt = $at;

        return $this;
    }

    public function getSeenUpTo(): ?\DateTimeImmutable
    {
        return $this->seenUpTo;
    }

    /** @return array<string, bool> */
    public function getPushPrefs(): array
    {
        return $this->pushPrefs;
    }

    /**
     * Whether this account wants one kind of push.
     *
     * @param string $kind one of PushNotifier::KINDS
     */
    public function wantsPush(string $kind): bool
    {
        return false !== ($this->pushPrefs[$kind] ?? true);
    }

    /**
     * Only the kinds actually named are touched, so a client that knows about
     * three kinds cannot silently reset a fourth it has never heard of — which
     * is what an older app version would do on every settings save.
     *
     * @param array<string, bool> $prefs
     */
    public function setPushPrefs(array $prefs): static
    {
        foreach ($prefs as $kind => $wanted) {
            $this->pushPrefs[$kind] = (bool) $wanted;
        }

        return $this;
    }

    public function setSeenUpTo(?\DateTimeImmutable $seenUpTo): static
    {
        $this->seenUpTo = $seenUpTo;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * The stored list, without the implied ROLE_USER.
     *
     * The admin UI edits this rather than getRoles(), or every save would write
     * ROLE_USER back into the column.
     *
     * @return list<string>
     */
    public function getGrantedRoles(): array
    {
        return array_values($this->roles);
    }

    /**
     * Whether this account holds a role directly.
     *
     * This does not consult role_hierarchy — ROLE_ADMIN implies ROLE_MODERATOR
     * for the firewall, not here. Callers that mean "may moderate" should ask
     * for both, which is what isModerator() does.
     */
    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isModerator(): bool
    {
        return $this->hasRole(self::ROLE_MODERATOR) || $this->isAdmin();
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /** Whether this account can be signed into with a password at all. */
    public function hasPassword(): bool
    {
        return null !== $this->password && '' !== $this->password;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : mb_strtolower(trim($email));

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;

        return $this;
    }

    public function isHandlePending(): bool
    {
        return $this->handlePending;
    }

    public function setHandlePending(bool $handlePending): static
    {
        $this->handlePending = $handlePending;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): static
    {
        $this->tagline = $tagline;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Silently falls back rather than throwing.
     *
     * A language this build cannot render is not a client error worth a 422 —
     * it is a language we have not shipped yet, and the honest answer to that
     * is English. Callers that do want to reject it validate before they get
     * here; the DTO does exactly that.
     */
    public function setLocale(?string $locale): static
    {
        $locale = strtolower(trim((string) $locale));

        $this->locale = \in_array($locale, self::SUPPORTED_LOCALES, true)
            ? $locale
            : self::DEFAULT_LOCALE;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /**
     * Checked against the system's zone database, not a list of our own.
     *
     * There are ~420 IANA zones and they change — Türkiye dropped its DST in
     * 2016 and got a new one. Hardcoding a subset here would mean shipping a
     * release every time the tzdata package moved, so the question asked is
     * simply whether PHP knows the name.
     */
    public function setTimezone(?string $timezone): static
    {
        $timezone = trim((string) $timezone);

        $this->timezone = \in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : self::DEFAULT_TIMEZONE;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }
}
