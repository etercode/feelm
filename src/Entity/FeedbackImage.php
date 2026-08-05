<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One screenshot on a report.
 *
 * `purgedAt` is why this is a row rather than a string on the feedback. Images
 * are the only part of this feature with a size problem — a paragraph of text
 * is nothing and a queue of screenshots is gigabytes — so they have to be
 * disposable independently of the report they belong to. A purged row keeps its
 * place in the record and stops pointing at a file, which is a better answer
 * than deleting the report or leaving a broken link.
 */
#[ORM\Entity]
#[ORM\Table(name: 'feedback_image')]
// The purge index is partial — CREATE INDEX ... WHERE purged_at IS NULL —
// which #[ORM\Index] cannot express, so the migration owns it alone. Declaring
// a plain index of the same name here is what makes every later diff propose
// dropping and recreating it.
#[ORM\Index(name: 'idx_feedback_image_feedback', columns: ['feedback_id', 'position'])]
class FeedbackImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Feedback::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Feedback $feedback = null;

    /** Public path, e.g. /media/feedback/2026-08/12-a1b2c3.jpg */
    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private int $bytes = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /** Set when the file was reclaimed; the row stays as a record that it existed. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purgedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFeedback(): ?Feedback
    {
        return $this->feedback;
    }

    public function setFeedback(Feedback $feedback): self
    {
        $this->feedback = $feedback;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function setBytes(int $bytes): self
    {
        $this->bytes = $bytes;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPurgedAt(): ?\DateTimeImmutable
    {
        return $this->purgedAt;
    }

    public function markPurged(): self
    {
        $this->purgedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isPurged(): bool
    {
        return null !== $this->purgedAt;
    }
}
