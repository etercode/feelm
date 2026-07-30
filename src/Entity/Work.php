<?php

namespace App\Entity;

use App\Repository\WorkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One catalogued creative work: a film, a series, a game or a book.
 *
 * Everything a person can put on a shelf is one of these. What differs per
 * type lives either in a column only that type fills (page_count) or in
 * `extra`, which is display-only — anything the app filters or sorts on is a
 * column, and anything a work can have several of is its own table.
 */
#[ORM\Entity(repositoryClass: WorkRepository::class)]
#[ORM\Table(name: 'works')]
#[ORM\UniqueConstraint(name: 'uniq_work_type_slug', columns: ['type', 'slug'])]
#[ORM\Index(name: 'idx_work_type_added', columns: ['type', 'added_at'])]
#[ORM\Index(name: 'idx_work_release_date', columns: ['release_date'])]
#[ORM\Index(name: 'idx_work_year', columns: ['year'])]
#[ORM\Index(name: 'idx_work_type_score', columns: ['type', 'external_score'])]
#[ORM\Index(name: 'idx_work_added_at', columns: ['added_at'])]
class Work
{
    public const TYPES = ['movie', 'series', 'game', 'book'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private ?string $type = null;

    #[ORM\Column(length: 180)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalTitle = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releaseDate = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $overview = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $poster = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $backdrop = null;

    /**
     * YouTube trailer payload: { site, key }, or null.
     *
     * @var array{site?: string, key?: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $trailer = null;

    /**
     * The preferred rating as a percentage, kept here because browse and search
     * sort by it and an indexed column beats a subquery per row. Derived from
     * work_ratings by a trigger — never written from PHP, or the two would
     * disagree the first time someone forgot.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, insertable: false, updatable: false)]
    private ?float $externalScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $voteCount = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $popularity = null;

    #[ORM\Column(nullable: true)]
    private ?int $runtimeMinutes = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $certification = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $originalLanguage = null;

    /** Books only. */
    #[ORM\Column(nullable: true)]
    private ?int $pageCount = null;

    /** Books and games. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $publisher = null;

    /**
     * Provenance: { name, url }, or null.
     *
     * @var array{name?: string, url?: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $source = null;

    /**
     * Display-only leftovers no filter touches: collection membership, game
     * platforms and perspectives, ISBNs. Never query against this.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $extra = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $addedAt = null;

    /** @var Collection<int, Genre> */
    #[ORM\ManyToMany(targetEntity: Genre::class, inversedBy: 'works')]
    #[ORM\JoinTable(name: 'work_genre')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $genres;

    /** @var Collection<int, Credit> */
    #[ORM\OneToMany(targetEntity: Credit::class, mappedBy: 'work', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $credits;

    /** @var Collection<int, ExternalId> */
    #[ORM\OneToMany(targetEntity: ExternalId::class, mappedBy: 'work', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $externalIds;

    /** @var Collection<int, WorkRating> */
    #[ORM\OneToMany(targetEntity: WorkRating::class, mappedBy: 'work', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ratings;

    /** @var Collection<int, Season> */
    #[ORM\OneToMany(targetEntity: Season::class, mappedBy: 'work', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $seasons;

    public function __construct()
    {
        $this->genres = new ArrayCollection();
        $this->credits = new ArrayCollection();
        $this->externalIds = new ArrayCollection();
        $this->ratings = new ArrayCollection();
        $this->seasons = new ArrayCollection();
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getOriginalTitle(): ?string
    {
        return $this->originalTitle;
    }

    public function setOriginalTitle(?string $originalTitle): static
    {
        $this->originalTitle = $originalTitle;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getReleaseDate(): ?\DateTimeImmutable
    {
        return $this->releaseDate;
    }

    public function setReleaseDate(?\DateTimeImmutable $releaseDate): static
    {
        $this->releaseDate = $releaseDate;

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

    public function getOverview(): ?string
    {
        return $this->overview;
    }

    public function setOverview(?string $overview): static
    {
        $this->overview = $overview;

        return $this;
    }

    public function getPoster(): ?string
    {
        return $this->poster;
    }

    public function setPoster(?string $poster): static
    {
        $this->poster = $poster;

        return $this;
    }

    public function getBackdrop(): ?string
    {
        return $this->backdrop;
    }

    public function setBackdrop(?string $backdrop): static
    {
        $this->backdrop = $backdrop;

        return $this;
    }

    /**
     * @return array{site?: string, key?: string}|null
     */
    public function getTrailer(): ?array
    {
        return $this->trailer;
    }

    /**
     * @param array{site?: string, key?: string}|null $trailer
     */
    public function setTrailer(?array $trailer): static
    {
        $this->trailer = $trailer;

        return $this;
    }

    public function getExternalScore(): ?float
    {
        return $this->externalScore;
    }


    public function getVoteCount(): ?int
    {
        return $this->voteCount;
    }

    public function setVoteCount(?int $voteCount): static
    {
        $this->voteCount = $voteCount;

        return $this;
    }

    public function getPopularity(): ?float
    {
        return $this->popularity;
    }

    public function setPopularity(?float $popularity): static
    {
        $this->popularity = $popularity;

        return $this;
    }

    public function getRuntimeMinutes(): ?int
    {
        return $this->runtimeMinutes;
    }

    public function setRuntimeMinutes(?int $runtimeMinutes): static
    {
        $this->runtimeMinutes = $runtimeMinutes;

        return $this;
    }

    public function getCertification(): ?string
    {
        return $this->certification;
    }

    public function setCertification(?string $certification): static
    {
        $this->certification = $certification;

        return $this;
    }

    public function getOriginalLanguage(): ?string
    {
        return $this->originalLanguage;
    }

    public function setOriginalLanguage(?string $originalLanguage): static
    {
        $this->originalLanguage = $originalLanguage;

        return $this;
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
    }

    public function setPageCount(?int $pageCount): static
    {
        $this->pageCount = $pageCount;

        return $this;
    }

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function setPublisher(?string $publisher): static
    {
        $this->publisher = $publisher;

        return $this;
    }

    /**
     * @return array{name?: string, url?: string}|null
     */
    public function getSource(): ?array
    {
        return $this->source;
    }

    /**
     * @param array{name?: string, url?: string}|null $source
     */
    public function setSource(?array $source): static
    {
        $this->source = $source;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function setExtra(array $extra): static
    {
        $this->extra = $extra;

        return $this;
    }

    public function getAddedAt(): ?\DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function setAddedAt(\DateTimeImmutable $addedAt): static
    {
        $this->addedAt = $addedAt;

        return $this;
    }

    /**
     * Derived, never stored: a release date in the future is the only thing
     * that makes a work upcoming, and it stops being true on its own.
     */
    public function isUpcoming(): bool
    {
        return null !== $this->releaseDate
            && $this->releaseDate > new \DateTimeImmutable('today');
    }

    /** @return Collection<int, Genre> */
    public function getGenres(): Collection
    {
        return $this->genres;
    }

    public function addGenre(Genre $genre): static
    {
        if (!$this->genres->contains($genre)) {
            $this->genres->add($genre);
        }

        return $this;
    }

    public function removeGenre(Genre $genre): static
    {
        $this->genres->removeElement($genre);

        return $this;
    }

    /** @return list<string> */
    public function getGenreNames(): array
    {
        return array_values(array_map(
            static fn (Genre $genre) => (string) $genre->getName(),
            $this->genres->toArray(),
        ));
    }

    /** @return Collection<int, Credit> */
    public function getCredits(): Collection
    {
        return $this->credits;
    }

    public function addCredit(Credit $credit): static
    {
        if (!$this->credits->contains($credit)) {
            $this->credits->add($credit);
            $credit->setWork($this);
        }

        return $this;
    }

    public function removeCredit(Credit $credit): static
    {
        $this->credits->removeElement($credit);

        return $this;
    }

    /** @return list<Credit> */
    public function getCreditsWithRole(string $role): array
    {
        return array_values(array_filter(
            $this->credits->toArray(),
            static fn (Credit $credit) => $credit->getRole() === $role,
        ));
    }

    /** @return Collection<int, ExternalId> */
    public function getExternalIds(): Collection
    {
        return $this->externalIds;
    }

    public function addExternalId(ExternalId $externalId): static
    {
        if (!$this->externalIds->contains($externalId)) {
            $this->externalIds->add($externalId);
            $externalId->setWork($this);
        }

        return $this;
    }

    public function getExternalId(string $source): ?string
    {
        foreach ($this->externalIds as $external) {
            if ($external->getSource() === $source) {
                return $external->getExternalId();
            }
        }

        return null;
    }


    /** @return Collection<int, WorkRating> */
    public function getRatings(): Collection
    {
        return $this->ratings;
    }

    public function addRating(WorkRating $rating): static
    {
        if (!$this->ratings->contains($rating)) {
            $this->ratings->add($rating);
            $rating->setWork($this);
        }

        return $this;
    }

    public function getRating(string $source): ?WorkRating
    {
        foreach ($this->ratings as $rating) {
            if ($rating->getSource() === $source) {
                return $rating;
            }
        }

        return null;
    }

    /** @return Collection<int, Season> */
    public function getSeasons(): Collection
    {
        return $this->seasons;
    }

    public function addSeason(Season $season): static
    {
        if (!$this->seasons->contains($season)) {
            $this->seasons->add($season);
            $season->setWork($this);
        }

        return $this;
    }

    public function getPath(): string
    {
        return $this->type.'/'.$this->slug;
    }
}
