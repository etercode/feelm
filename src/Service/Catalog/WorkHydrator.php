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
 * Anything that presents more than one work at a time wants this first.
 */
final class WorkHydrator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Fills the collections of already-loaded works. Doctrine's identity map is
     * what carries the result across — the works handed in come back populated,
     * so nothing is returned.
     *
     * @param list<Work> $works
     */
    public function preload(array $works): void
    {
        $ids = [];
        foreach ($works as $work) {
            $id = $work->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        $this->preloadIds($ids);
    }

    /**
     * @param list<int> $ids
     */
    public function preloadIds(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        /*
         * One query per collection rather than one joining all of them: joined
         * together they multiply, so a film with 3 genres, 2 ratings and 14
         * credits would come back as 84 rows repeating the same film.
         */
        $this->loadCollection($ids, 'genres');
        $this->loadCollection($ids, 'ratings');
        $this->loadCollection($ids, 'externalIds');
        $this->loadCollection($ids, 'credits', 'person');
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
