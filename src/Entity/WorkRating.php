<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one source thinks of a work.
 *
 * The rating is kept in the units that source publishes — IMDb 7.4 out of 10,
 * TMDB 87 out of 100 — with the scale alongside it, because rounding everything
 * to a percentage on the way in loses the number people recognise.
 */
#[ORM\Entity]
#[ORM\Table(name: 'work_ratings')]
#[ORM\UniqueConstraint(name: 'uniq_work_rating_source', columns: ['work_id', 'source'])]
#[ORM\Index(name: 'idx_work_rating_source_value', columns: ['source', 'rating'])]
#[ORM\Index(name: 'idx_work_rating_work', columns: ['work_id'])]
class WorkRating
{
    public const SOURCE_IMDB = 'imdb';
    public const SOURCE_TMDB = 'tmdb';
    public const SOURCE_METACRITIC = 'metacritic';
    public const SOURCE_STEAM = 'steam';

    public const SOURCES = [
        self::SOURCE_IMDB,
        self::SOURCE_TMDB,
        self::SOURCE_METACRITIC,
        self::SOURCE_STEAM,
    ];

    /** Which source wins when several have an opinion. */
    public const PREFERENCE = [self::SOURCE_IMDB, self::SOURCE_TMDB, self::SOURCE_METACRITIC, self::SOURCE_STEAM];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Work::class, inversedBy: 'ratings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Work $work = null;

    #[ORM\Column(length: 16)]
    private ?string $source = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private ?string $rating = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 10])]
    private int $scale = 10;

    #[ORM\Column(nullable: true)]
    private ?int $votes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(?string $source = null)
    {
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();
    }

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

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        if (!\in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown rating source "%s".', $source));
        }
        $this->source = $source;

        return $this;
    }

    public function getRating(): ?float
    {
        return null === $this->rating ? null : (float) $this->rating;
    }

    public function setRating(float $rating): static
    {
        $this->rating = number_format($rating, 2, '.', '');

        return $this;
    }

    public function getScale(): int
    {
        return $this->scale;
    }

    public function setScale(int $scale): static
    {
        $this->scale = $scale;

        return $this;
    }

    public function getVotes(): ?int
    {
        return $this->votes;
    }

    public function setVotes(?int $votes): static
    {
        $this->votes = $votes;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** The rating as a percentage, for comparing sources with each other. */
    public function getNormalised(): ?float
    {
        $rating = $this->getRating();

        return null === $rating || 0 === $this->scale ? null : round($rating / $this->scale * 100, 1);
    }
}
