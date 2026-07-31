<?php

namespace App\Controller\Api\Admin;

use App\Dto\Admin\AdminWorkRequest;
use App\Entity\User;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The catalog, as the admin sees it — including the parts of it nobody else
 * can.
 *
 * A moderator may look and may correct a title; hiding one from the catalog
 * and putting it back are an administrator's, because a hidden work vanishes
 * from everybody's shelves at once.
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

    private const STATUSES = ['active', 'deleted', 'all'];

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
     */
    #[Route('/api/admin/works/{id}', name: 'api_admin_works_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function hide(int $id, WorkAdmin $admin): JsonResponse
    {
        try {
            $admin->hide($this->mustFind($id));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/works/{id}/restore', name: 'api_admin_works_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(User::ROLE_ADMIN)]
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
