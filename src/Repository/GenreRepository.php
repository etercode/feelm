<?php

namespace App\Repository;

use App\Entity\Genre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Genre>
 */
class GenreRepository extends ServiceEntityRepository
{
    /**
     * Genres created during this flush cycle — same reason as people: an
     * un-flushed row is invisible to findOneBy().
     *
     * @var array<string, Genre>
     */
    private array $pending = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Genre::class);
    }

    /**
     * Genre rows for the given names, creating any that are new. Called once
     * per crawled work, so the whole table is kept in an identity map.
     *
     * @param list<string> $names
     *
     * @return list<Genre>
     */
    public function findOrCreateMany(array $names): array
    {
        $genres = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }

            // Both columns are varchar(80).
            $slug = mb_substr(self::slugify($name), 0, 80);
            $genre = $this->pending[$slug] ?? $this->findOneBy(['slug' => $slug]);
            if (null === $genre) {
                $genre = new Genre($slug, mb_substr($name, 0, 80));
                $this->getEntityManager()->persist($genre);
                $this->pending[$slug] = $genre;
            }
            $genres[$slug] = $genre;
        }

        return array_values($genres);
    }

    /**
     * @return list<Genre>
     */
    public function allSorted(): array
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Called when the entity manager is cleared: the pending objects are gone. */
    public function resetPending(): void
    {
        $this->pending = [];
    }

    /**
     * Unicode-preserving slug: lowercase, and every run of non-alphanumerics
     * becomes a dash. Transliterating to ASCII first looked tidier but mangled
     * real names — iconv turns "Clément" into "cl-ement" and drops CJK names
     * to nothing at all, so every Japanese credit would have collapsed into one
     * person. Postgres [[:alnum:]] folds the same way, which keeps the crawler
     * and the migrations agreeing.
     */
    public static function slugify(string $name): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(trim($name))) ?? '';

        return trim($slug, '-') ?: 'unknown';
    }
}
