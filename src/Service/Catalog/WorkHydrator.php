<?php

namespace App\Service\Catalog;

use App\Entity\Work;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads everything WorkPresenter reads, for a set of works, in four queries.
 *
 * Without this the presenter walks each work's collections and Doctrine fetches
 * them one work at a time — and one person at a time behind the credits, which
 * is the expensive part. A page of 48 films cost about 2,200 queries and a
 * second and a half; batched, it is five.
 *
 * Anything that presents more than one work at a time wants this first — and
 * anything presenting less than the whole payload should say which parts it
 * needs, or it pays for a collection it never reads.
 */
final class WorkHydrator
{
    public const GENRES = 'genres';
    public const RATINGS = 'ratings';
    public const EXTERNAL_IDS = 'externalIds';
    public const CREDITS = 'credits';

    /** @var list<string> */
    public const ALL = [self::GENRES, self::RATINGS, self::EXTERNAL_IDS, self::CREDITS];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Fills the collections of already-loaded works. Doctrine's identity map is
     * what carries the result across — the works handed in come back populated,
     * so nothing is returned.
     *
     * @param list<Work>   $works
     * @param list<string> $only which collections to load; everything by default
     */
    public function preload(array $works, array $only = self::ALL): void
    {
        $ids = [];
        foreach ($works as $work) {
            $id = $work->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        $this->preloadIds($ids, $only);
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $only
     */
    public function preloadIds(array $ids, array $only = self::ALL): void
    {
        if ([] === $ids) {
            return;
        }

        /*
         * One query per collection rather than one joining all of them: joined
         * together they multiply, so a film with 3 genres, 2 ratings and 14
         * credits would come back as 84 rows repeating the same film.
         *
         * Each one re-selects the work's own columns as well, which looks like
         * waste and is not avoidable: DQL cannot hydrate a joined collection
         * onto its owner without selecting the owner too, and partial selects
         * are gone in ORM 3. Twenty extra work rows per collection is cheaper
         * than the round trip it would save.
         */
        if (\in_array(self::GENRES, $only, true)) {
            $this->loadCollection($ids, 'genres');
        }
        if (\in_array(self::RATINGS, $only, true)) {
            $this->loadCollection($ids, 'ratings');
        }
        if (\in_array(self::EXTERNAL_IDS, $only, true)) {
            $this->loadCollection($ids, 'externalIds');
        }
        if (\in_array(self::CREDITS, $only, true)) {
            $this->loadCollection($ids, 'credits', 'person');
        }
    }

    /**
     * @param list<int> $ids
     */
    private function loadCollection(array $ids, string $association, ?string $nested = null): void
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('w', 'a')
            ->from(Work::class, 'w')
            ->leftJoin('w.'.$association, 'a')
            ->where('w.id IN (:ids)')
            ->setParameter('ids', $ids);

        if (null !== $nested) {
            $builder->addSelect('n')->leftJoin('a.'.$nested, 'n');
        }

        $builder->getQuery()->getResult();
    }
}
