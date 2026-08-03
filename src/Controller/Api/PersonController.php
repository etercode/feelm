<?php

namespace App\Controller\Api;

use App\Entity\Credit;
use App\Presenter\WorkPresenter;
use App\Repository\PersonRepository;
use App\Repository\WorkRepository;
use App\Service\Catalog\WorkHydrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A person, and everything the catalog has them on.
 *
 * The cast list on a detail page was a dead end: names and faces with nowhere
 * to go, when "what else are they in" is the obvious next question. Search
 * could already answer it — `?person=` has been a filter for a while — but
 * nothing linked to it and a search result page is a poor answer to a question
 * about one person anyway. It says nothing about what they *did* on each title.
 */
final class PersonController extends AbstractController
{
    /**
     * Credits to return.
     *
     * Generous rather than paged, because a filmography is a thing people read
     * whole and the tail is where the interesting entries hide. Two hundred
     * covers everybody: the busiest person in the catalog is a long way short
     * of it, and the cost is the poster wall rather than the query.
     */
    private const CREDITS = 200;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly WorkRepository $works,
        private readonly WorkPresenter $presenter,
        private readonly WorkHydrator $hydrator,
    ) {
    }

    #[Route('/api/people/{slug}', name: 'api_person_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $person = $this->people->findBySlug($slug);
        if (null === $person) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $credits = $this->people->creditsFor((int) $person->getId(), self::CREDITS);

        /*
         * One fetch for every work they are on, then one preload for the
         * artwork and ratings the cards draw. Walking the credits and loading
         * a work apiece is the shape that turns a busy actor into two hundred
         * queries.
         */
        $works = [] === $credits
            ? []
            : $this->works->findBy(['id' => array_column($credits, 'workId')]);

        $this->hydrator->preload($works, [WorkHydrator::RATINGS]);

        /** @var array<int, \App\Entity\Work> $byId */
        $byId = [];
        foreach ($works as $work) {
            $byId[(int) $work->getId()] = $work;
        }

        /*
         * Grouped by what they did, in the order a filmography reads: acting
         * first because that is what most people are looked up for, then the
         * crew roles. A person can appear in two groups for the same title —
         * directed it and had a cameo — and both are true.
         */
        $groups = [];
        foreach ($credits as $credit) {
            $work = $byId[$credit['workId']] ?? null;
            if (null === $work) {
                continue;
            }

            $groups[$credit['role']][] = [
                ...$this->presenter->listItem($work),
                'character' => $credit['character'],
            ];
        }

        $ordered = [];
        foreach (self::roleOrder() as $role) {
            if (isset($groups[$role])) {
                $ordered[] = ['role' => $role, 'items' => $groups[$role]];
            }
        }

        return $this->json([
            'person' => [
                'slug' => $person->getSlug(),
                'name' => $person->getName(),
                'photo' => $person->getPhoto(),
            ],
            'credits' => $ordered,
            'total' => array_sum(array_map(static fn (array $g) => \count($g['items']), $ordered)),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function roleOrder(): array
    {
        return [
            Credit::ROLE_CAST,
            Credit::ROLE_DIRECTOR,
            Credit::ROLE_WRITER,
            Credit::ROLE_CREATOR,
            Credit::ROLE_DEVELOPER,
            Credit::ROLE_PUBLISHER,
            Credit::ROLE_AUTHOR,
        ];
    }
}
