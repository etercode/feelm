<?php

namespace App\Controller\Api\Admin;

use App\Dto\Admin\AdminCreditRequest;
use App\Entity\Credit;
use App\Entity\Work;
use App\Presenter\PersonPresenter;
use App\Repository\CreditRepository;
use App\Repository\WorkRepository;
use App\Service\Admin\PeopleAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who is credited on a work.
 *
 * Hung off the work rather than off people, because that is the direction the
 * question comes from: a cast list is wrong, and it is wrong about one film.
 *
 * A credit names its person by name, not by id. An unfamiliar name creates a
 * person, which is exactly what the crawler does — the alternative would be
 * making somebody create the person first and then come back.
 */
class CreditAdminController extends AbstractController
{
    public function __construct(
        private readonly WorkRepository $works,
        private readonly CreditRepository $credits,
        private readonly PersonPresenter $presenter,
    ) {
    }

    #[Route('/api/admin/works/{id}/credits', name: 'api_admin_credits_index', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function index(int $id): JsonResponse
    {
        return $this->json([
            'items' => array_map(
                $this->presenter->credit(...),
                $this->credits->forWork($this->mustFindWork($id)),
            ),
            'roles' => Credit::ROLES,
        ]);
    }

    #[Route('/api/admin/works/{id}/credits', name: 'api_admin_credits_create', methods: ['POST'], requirements: ['id' => '\d+'], format: 'json')]
    public function create(
        int $id,
        #[MapRequestPayload] AdminCreditRequest $payload,
        PeopleAdmin $admin,
    ): JsonResponse {
        $work = $this->mustFindWork($id);

        if (null === $payload->person || null === $payload->role) {
            return $this->json(['error' => 'person_and_role_required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $credit = $admin->addCredit($work, $payload->person, $payload->role, $payload->character);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], $this->statusFor($e));
        }

        return $this->json($this->presenter->credit($credit), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/credits/{id}', name: 'api_admin_credits_update', methods: ['PATCH'], requirements: ['id' => '\d+'], format: 'json')]
    public function update(
        int $id,
        #[MapRequestPayload] AdminCreditRequest $payload,
        PeopleAdmin $admin,
    ): JsonResponse {
        $credit = $this->mustFindCredit($id);

        try {
            $admin->updateCredit($credit, $payload->role, $payload->character, $payload->position);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], $this->statusFor($e));
        }

        return $this->json($this->presenter->credit($credit));
    }

    #[Route('/api/admin/credits/{id}', name: 'api_admin_credits_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, PeopleAdmin $admin): JsonResponse
    {
        $admin->removeCredit($this->mustFindCredit($id));

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /* ------------------------------------------------------------- private */

    private function mustFindWork(int $id): Work
    {
        $work = $this->works->find($id);
        if (null === $work) {
            throw new NotFoundHttpException('Work not found.');
        }

        return $work;
    }

    private function mustFindCredit(int $id): Credit
    {
        $credit = $this->credits->find($id);
        if (null === $credit) {
            throw new NotFoundHttpException('Credit not found.');
        }

        return $credit;
    }

    private function statusFor(\InvalidArgumentException $e): int
    {
        return 'duplicate_credit' === $e->getMessage()
            ? Response::HTTP_CONFLICT
            : Response::HTTP_BAD_REQUEST;
    }
}
