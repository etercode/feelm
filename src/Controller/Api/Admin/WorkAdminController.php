<?php

namespace App\Controller\Api\Admin;

use App\Dto\Admin\AdminWorkRequest;
use App\Entity\Work;
use App\Presenter\WorkPresenter;
use App\Repository\GenreRepository;
use App\Repository\WorkRepository;
use App\Service\Admin\WorkAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalog, as the admin sees it — including the parts of it nobody else
 * can.
 *
 * Everything here sits at the firewall's ROLE_MODERATOR floor. Hiding a title
 * and putting it back used to ask for ROLE_ADMIN, on the grounds that a hidden
 * work leaves everybody's shelves at once — but it is reversible by the same
 * person in the same screen, and clearing the catalogue of artwork we cannot
 * show is the moderation job itself rather than an escalation of it.
 *
 * There is no create endpoint. Works come from the crawler, which owns their
 * identity through external_ids; a hand-made row with no external id would be
 * invisible to it and get inserted again from TMDB the moment that title came
 * round.
 */
class WorkAdminController extends AbstractController
{
    private const MAX_LIMIT = 100;

    private const SORTS = ['popular', 'title', 'year', 'oldest', 'added', 'score', 'hidden'];

    private const STATUSES = ['active', 'deleted', 'adult', 'all'];

    private const MISSING = ['poster', 'overview', 'year', 'genre', 'imdb'];

    public function __construct(
        private readonly WorkRepository $works,
        private readonly WorkPresenter $presenter,
    ) {
    }

    #[Route('/api/admin/works', name: 'api_admin_works_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 25)));

        $result = $this->works->adminPage(
            [
                'q' => $request->query->get('q'),
                'type' => $this->oneOf($request->query->get('type'), Work::TYPES),
                'status' => $this->oneOf($request->query->get('status'), self::STATUSES),
                'missing' => $this->oneOf($request->query->get('missing'), self::MISSING),
                'genre' => $request->query->get('genre') ?: null,
                'yearFrom' => $request->query->getInt('yearFrom') ?: null,
                'yearTo' => $request->query->getInt('yearTo') ?: null,
                'sort' => $this->oneOf($request->query->get('sort'), self::SORTS),
            ],
            ($page - 1) * $limit,
            $limit,
        );

        return $this->json([
            'items' => array_map($this->presenter->adminRow(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
        ]);
    }

    /** Every genre, for the filter and the editor's suggestions. */
    #[Route('/api/admin/works/genres', name: 'api_admin_works_genres', methods: ['GET'])]
    public function genres(GenreRepository $genres): JsonResponse
    {
        return $this->json([
            'items' => array_map(
                static fn ($genre) => ['slug' => $genre->getSlug(), 'name' => $genre->getName()],
                $genres->allSorted(),
            ),
        ]);
    }

    #[Route('/api/admin/works/{id}', name: 'api_admin_works_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->presenter->adminOne($this->mustFind($id)));
    }

    #[Route('/api/admin/works/{id}', name: 'api_admin_works_update', methods: ['PATCH'], requirements: ['id' => '\d+'], format: 'json')]
    public function update(
        int $id,
        #[MapRequestPayload] AdminWorkRequest $payload,
        WorkAdmin $admin,
    ): JsonResponse {
        $work = $this->mustFind($id);

        try {
            $admin->update($work, $payload);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->presenter->adminOne($work));
    }

    /**
     * Hides a work from the catalog. Not a delete — see WorkAdmin.
     *
     * ROLE_MODERATOR, the firewall's floor for /api/admin. It was ROLE_ADMIN,
     * which left a moderator able to hide a hundred titles through the bulk
     * route and not one on its own — a boundary that only meant the same person
     * took the longer way round.
     */
    #[Route('/api/admin/works/{id}', name: 'api_admin_works_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function hide(int $id, WorkAdmin $admin): JsonResponse
    {
        try {
            $admin->hide($this->mustFind($id));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The same actions over a selection.
     *
     * ROLE_MODERATOR, like hide and restore either side of it. Clearing the
     * catalogue of artwork we cannot show is moderation work, it is done a
     * filmography at a time, and everything it does is reversible by the same
     * endpoint.
     */
    #[Route('/api/admin/works/bulk', name: 'api_admin_works_bulk', methods: ['POST'], format: 'json')]
    public function bulk(Request $request, WorkAdmin $admin): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $action = \is_array($payload) ? ($payload['action'] ?? null) : null;
        $ids = \is_array($payload) && \is_array($payload['ids'] ?? null) ? $payload['ids'] : [];

        // Cast and de-duplicate here rather than trusting the browser: the same
        // title selected twice would otherwise be counted twice in the reply.
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ([] === $ids) {
            return $this->json(['error' => 'no_ids'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (\count($ids) > WorkAdmin::BULK_LIMIT) {
            return $this->json(['error' => 'too_many'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $admin->bulk($ids, (string) $action);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($result);
    }

    /** ROLE_MODERATOR, like hide — undoing it cannot need more rights than doing it. */
    #[Route('/api/admin/works/{id}/restore', name: 'api_admin_works_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, WorkAdmin $admin): JsonResponse
    {
        $work = $this->mustFind($id);

        try {
            $admin->restore($work);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->adminOne($work));
    }

    /* ------------------------------------------------------------- private */

    /** Hidden works are addressable here — that is how one gets restored. */
    private function mustFind(int $id): Work
    {
        $work = $this->works->find($id);
        if (null === $work) {
            throw new NotFoundHttpException('Work not found.');
        }

        return $work;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }
}
