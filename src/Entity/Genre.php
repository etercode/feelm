<?php

namespace App\Entity;

use App\Repository\GenreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A genre is a row, not a string in a JSON array: that is what lets the
 * browse filters join, the facet counts group, and a rename happen once.
 */
#[ORM\Entity(repositoryClass: GenreRepository::class)]
#[ORM\Table(name: 'genres')]
#[ORM\UniqueConstraint(name: 'uniq_genre_slug', columns: ['slug'])]
class Genre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private ?string $slug = null;

    #[ORM\Column(length: 80)]
    private ?string $name = null;

    /** @var Collection<int, Work> */
    #[ORM\ManyToMany(targetEntity: Work::class, mappedBy: 'genres')]
    private Collection $works;

    public function __construct(?string $slug = null, ?string $name = null)
    {
        $this->works = new ArrayCollection();
        $this->slug = $slug;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return Collection<int, Work> */
    public function getWorks(): Collection
    {
        return $this->works;
    }
}
