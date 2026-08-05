<?php

namespace App\Entity;

use App\Repository\FeedbackRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something a signed-in person wants us to know about.
 *
 * ---- who may change what -----------------------------------------------------
 *
 * The status is the lock. While a report is NEW it belongs to whoever wrote it:
 * they can edit the text, add and remove screenshots, or withdraw it entirely.
 * The moment an administrator accepts it, it stops being theirs — it is on a
 * list of work now, and a queue that can be rewritten underneath you is not a
 * queue. From then on only an administrator can touch it.
 *
 * DECLINED is deliberately not deletion. Someone took the trouble to write it,
 * and "read and not doing it" is a more honest answer than a report that
 * silently disappears.
 */
#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedback')]
#[ORM\Index(name: 'idx_feedback_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_feedback_user', columns: ['user_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class Feedback
{
    public const STATUS_NEW = 'new';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DONE = 'done';
    public const STATUS_DECLINED = 'declined';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_ACCEPTED, self::STATUS_DONE, self::STATUS_DECLINED];

    public const CATEGORY_BUG = 'bug';
    public const CATEGORY_IDEA = 'idea';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [self::CATEGORY_BUG, self::CATEGORY_IDEA, self::CATEGORY_OTHER];

    /** How many screenshots one report may carry. */
    public const MAX_IMAGES = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 16)]
    private string $category = self::CATEGORY_BUG;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $body = null;

    /**
     * The administrator's reply, shown to the author.
     *
     * Its whole purpose is that "declined" should be able to say why. A status
     * on its own reads as a shrug.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** When it stopped belonging to its author. Null while it is still theirs. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @var Collection<int, FeedbackImage> */
    #[ORM\OneToMany(targetEntity: FeedbackImage::class, mappedBy: 'feedback', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $images;

    public function __construct()
    {
        $this->images = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Whether the person who wrote this may still change it.
     *
     * One method rather than the same status comparison in the controller, the
     * service and the presenter — the rule is the feature, and it should be
     * stated once.
     */
    public function isEditableByAuthor(): bool
    {
        return self::STATUS_NEW === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        if (!\in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('invalid_category');
        }
        $this->category = $category;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Moves the report, and stamps the two dates that follow from the move.
     *
     * The stamps live here rather than in the service because they are not
     * decisions — acceptedAt is simply what "accepted" means, and a caller that
     * forgot one would leave a row that contradicts itself.
     */
    public function setStatus(string $status): self
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('invalid_status');
        }

        $now = new \DateTimeImmutable();

        // Set once and kept: the first acceptance is when the author lost the
        // right to edit, and moving on to done must not rewrite that history.
        if (self::STATUS_NEW !== $status && null === $this->acceptedAt) {
            $this->acceptedAt = $now;
        }

        // Back to new hands it back, so the stamp has to go with it.
        if (self::STATUS_NEW === $status) {
            $this->acceptedAt = null;
        }

        $this->resolvedAt = \in_array($status, [self::STATUS_DONE, self::STATUS_DECLINED], true)
            ? ($this->resolvedAt ?? $now)
            : null;

        $this->status = $status;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = '' === $note ? null : $note;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /** @return Collection<int, FeedbackImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(FeedbackImage $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setFeedback($this);
        }

        return $this;
    }

    public function removeImage(FeedbackImage $image): self
    {
        $this->images->removeElement($image);

        return $this;
    }
}
