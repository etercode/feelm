<?php

namespace App\Controller\Api;

use App\Dto\FeedbackRequest;
use App\Entity\Feedback;
use App\Entity\User;
use App\Presenter\FeedbackPresenter;
use App\Repository\FeedbackRepository;
use App\Service\Feedback\FeedbackService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A person's own reports.
 *
 * Every route here is scoped to the caller — there is no "read someone else's
 * feedback" on this side of the API, and the id in the path is checked against
 * the signed-in user rather than trusted. The admin equivalent lives under
 * /api/admin/feedback and is a separate controller for that reason.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class FeedbackController extends AbstractController
{
    private const MAX_LIMIT = 50;

    #[Route('/api/feedback', name: 'api_feedback_list', methods: ['GET'], format: 'json')]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        FeedbackRepository $repository,
    ): JsonResponse {
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', 20)));
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $repository->pageForUser($user, ($page - 1) * $limit, $limit);

        return $this->json([
            'items' => array_map(FeedbackPresenter::one(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'pages' => (int) ceil($result['total'] / $limit),
            'limit' => $limit,
        ]);
    }

    #[Route('/api/feedback', name: 'api_feedback_create', methods: ['POST'], format: 'json')]
    public function create(
        #[MapRequestPayload] FeedbackRequest $payload,
        #[CurrentUser] User $user,
        FeedbackService $service,
    ): JsonResponse {
        if (null === $payload->body || '' === trim($payload->body)) {
            return $this->json(['error' => 'body_required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $report = $service->create($user, $payload->category ?? Feedback::CATEGORY_BUG, trim($payload->body));

        return $this->json(FeedbackPresenter::one($report), Response::HTTP_CREATED);
    }

    #[Route('/api/feedback/{id}', name: 'api_feedback_update', methods: ['PATCH'], format: 'json')]
    public function update(
        int $id,
        #[MapRequestPayload] FeedbackRequest $payload,
        #[CurrentUser] User $user,
        FeedbackRepository $repository,
        FeedbackService $service,
    ): JsonResponse {
        $report = $this->mine($repository, $id, $user);

        try {
            $service->updateByAuthor($report, $user, $payload->category, $payload->body);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(FeedbackPresenter::one($report));
    }

    #[Route('/api/feedback/{id}', name: 'api_feedback_delete', methods: ['DELETE'], format: 'json')]
    public function delete(
        int $id,
        #[CurrentUser] User $user,
        FeedbackRepository $repository,
        FeedbackService $service,
    ): JsonResponse {
        $report = $this->mine($repository, $id, $user);

        try {
            $service->deleteByAuthor($report, $user);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * One screenshot per request.
     *
     * Multipart rather than a base64 field in the JSON: the browser can send a
     * pasted or dropped file directly, and base64 would inflate an 8MB cap into
     * an 11MB body for no gain. The client posts them one at a time so a single
     * rejected image does not lose the other three.
     */
    #[Route('/api/feedback/{id}/images', name: 'api_feedback_add_image', methods: ['POST'])]
    public function addImage(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
        FeedbackRepository $repository,
        FeedbackService $service,
    ): JsonResponse {
        $report = $this->mine($repository, $id, $user);
        $file = $request->files->get('image');

        if (null === $file) {
            return $this->json(['error' => 'no_file'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $service->addImage($report, $user, $file);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(FeedbackPresenter::one($report), Response::HTTP_CREATED);
    }

    #[Route('/api/feedback/{id}/images/{imageId}', name: 'api_feedback_remove_image', methods: ['DELETE'], format: 'json')]
    public function removeImage(
        int $id,
        int $imageId,
        #[CurrentUser] User $user,
        FeedbackRepository $repository,
        FeedbackService $service,
    ): JsonResponse {
        $report = $this->mine($repository, $id, $user);

        try {
            $service->removeImage($report, $user, $imageId);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(FeedbackPresenter::one($report));
    }

    /**
     * Somebody else's report is a 404 here, not a 403.
     *
     * A 403 would confirm that the id exists, which is more than a stranger
     * needs to know about a queue they cannot read.
     */
    private function mine(FeedbackRepository $repository, int $id, User $user): Feedback
    {
        $report = $repository->find($id);

        if (null === $report || $report->getUser()?->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Feedback not found.');
        }

        return $report;
    }
}
