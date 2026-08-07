<?php

namespace App\Controller\Api\Admin;

use App\Dto\Admin\AdminPersonRequest;
use App\Entity\Credit;
use App\Entity\Person;
use App\Entity\User;
use App\Presenter\PersonPresenter;
use App\Repository\CreditRepository;
use App\Repository\PersonRepository;
use App\Service\Admin\PeopleAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * People — 1.1 million of them, one per name the crawler has ever seen.
 *
 * A moderator may correct a name or a photo. Merging two people and deleting
 * one are an administrator's: both move or destroy credits across the catalog,
 * and neither can be undone.
 */
class PersonAdminController extends AbstractController
{
    private const MAX_LIMIT = 100;

    private const SORTS = ['credits', 'fewest', 'name', 'newest'];

    private const YES_NO = ['yes', 'no'];

    private const CREDITS = ['some', 'none'];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly CreditRepository $credits,
        private readonly PersonPresenter $presenter,
    ) {
    }

    #[Route('/api/admin/people', name: 'api_admin_people_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 25)));

        $result = $this->people->adminPage(
            [
                'q' => $request->query->get('q'),
                'photo' => $this->oneOf($request->query->get('photo'), self::YES_NO),
                'credits' => $this->oneOf($request->query->get('credits'), self::CREDITS),
                'sort' => $this->oneOf($request->query->get('sort'), self::SORTS),
            ],
            ($page - 1) * $limit,
            $limit,
        );

        return $this->json([
            'items' => array_map(
                fn (array $row) => $this->presenter->one($row['person'], $row['credits']),
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
        ]);
    }

    /** Type-ahead for the merge picker and the credit editor. */
    #[Route('/api/admin/people/search', name: 'api_admin_people_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        if (mb_strlen($term) < 2) {
            return $this->json(['items' => []]);
        }

        $found = $this->people->searchEntitiesByName($term, min(20, max(1, $request->query->getInt('limit', 8))));
        $counts = $this->people->creditCountsFor(array_map(
            static fn (Person $person) => (int) $person->getId(),
            $found,
        ));

        return $this->json([
            'items' => array_map(
                fn (Person $person) => $this->presenter->one($person, $counts[$person->getId()] ?? 0),
                $found,
            ),
        ]);
    }

    #[Route('/api/admin/people/{id}', name: 'api_admin_people_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): JsonResponse
    {
        $person = $this->mustFind($id);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 40)));

        $credits = $this->credits->forPerson($person, ($page - 1) * $limit, $limit);

        return $this->json([
            ...$this->presenter->one($person, $credits['total']),
            'credits' => [
                'items' => array_map($this->presenter->creditOfWork(...), $credits['items']),
                'total' => $credits['total'],
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($credits['total'] / $limit),
            ],
        ]);
    }

    #[Route('/api/admin/people/{id}', name: 'api_admin_people_update', methods: ['PATCH'], requirements: ['id' => '\d+'], format: 'json')]
    public function update(
        int $id,
        #[MapRequestPayload] AdminPersonRequest $payload,
        PeopleAdmin $admin,
    ): JsonResponse {
        $person = $this->mustFind($id);

        try {
            $admin->update($person, $payload->name, $payload->photo, $payload->externalId);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->presenter->one($person, $this->credits->count(['person' => $person])));
    }

    /**
     * Folds this person into another. The one named in the body survives.
     */
    #[Route('/api/admin/people/{id}/merge/{into}', name: 'api_admin_people_merge', methods: ['POST'], requirements: ['id' => '\d+', 'into' => '\d+'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function merge(int $id, int $into, PeopleAdmin $admin): JsonResponse
    {
        $loser = $this->mustFind($id);
        $winner = $this->mustFind($into);

        try {
            $moved = $admin->merge($loser, $winner);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        $fresh = $this->mustFind($into);

        return $this->json([
            'merged' => $moved,
            'person' => $this->presenter->one($fresh, $this->credits->count(['person' => $fresh])),
        ]);
    }

    #[Route('/api/admin/people/{id}', name: 'api_admin_people_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function delete(int $id, Request $request, PeopleAdmin $admin): JsonResponse
    {
        try {
            $admin->delete($this->mustFind($id), $request->query->getBoolean('force'));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /* ------------------------------------------------------------- private */

    private function mustFind(int $id): Person
    {
        $person = $this->people->find($id);
        if (null === $person) {
            throw new NotFoundHttpException('Person not found.');
        }

        return $person;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }
}
