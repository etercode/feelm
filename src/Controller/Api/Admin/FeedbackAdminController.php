<?php

namespace App\Controller\Api\Admin;

use App\Dto\FeedbackRequest;
use App\Entity\Feedback;
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

/**
 * The moderation queue.
 *
 * The firewall already puts a ROLE_MODERATOR floor under /api/admin, which is
 * the right level to read and triage at. Deleting is the one thing here that
 * destroys something a person wrote, so it asks for ROLE_ADMIN on the method.
 */
class FeedbackAdminController extends AbstractController
{
    private const MAX_LIMIT = 100;

    #[Route('/api/admin/feedback', name: 'api_admin_feedback_list', methods: ['GET'], format: 'json')]
    public function index(Request $request, FeedbackRepository $repository): JsonResponse
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', 25)));
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $repository->page([
            'status' => self::oneOf($request->query->get('status'), Feedback::STATUSES),
            'category' => self::oneOf($request->query->get('category'), Feedback::CATEGORIES),
            'q' => $request->query->get('q'),
            'sort' => self::oneOf($request->query->get('sort'), ['queue', 'recent', 'oldest']),
        ], ($page - 1) * $limit, $limit);

        return $this->json([
            'items' => array_map(FeedbackPresenter::admin(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'pages' => (int) ceil($result['total'] / $limit),
            'limit' => $limit,
            // Drives the badge on the sidebar, and saves the list page a second
            // request just to say how many are waiting.
            'counts' => $repository->countsByStatus(),
        ]);
    }

    #[Route('/api/admin/feedback/{id}', name: 'api_admin_feedback_show', methods: ['GET'], format: 'json')]
    public function show(int $id, FeedbackRepository $repository): JsonResponse
    {
        return $this->json(FeedbackPresenter::admin($this->find($repository, $id)));
    }

    /**
     * Accept, decline, or mark done.
     *
     * Status and note in one call because they are one action: declining
     * without saying why is the thing this is trying to avoid.
     */
    #[Route('/api/admin/feedback/{id}/status', name: 'api_admin_feedback_status', methods: ['POST'], format: 'json')]
    public function status(
        int $id,
        Request $request,
        FeedbackRepository $repository,
        FeedbackService $service,
    ): JsonResponse {
        $report = $this->find($repository, $id);
        $payload = json_decode($request->getContent() ?: '{}', true);
        $status = \is_array($payload) ? ($payload['status'] ?? null) : null;

        if (!\in_array($status, Feedback::STATUSES, true)) {
            return $this->json(['error' => 'invalid_status'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $note = \is_array($payload) && \is_string($payload['note'] ?? null) ? $payload['note'] : null;
        $service->moderate($report, $status, $note);

        return $this->json(FeedbackPresenter::admin($report));
    }

    #[Route('/api/admin/feedback/{id}', name: 'api_admin_feedback_update', methods: ['PATCH'], format: 'json')]
    public function update(
        int $id,
        Request $request,
        #[MapRequestPayload] FeedbackRequest $payload,
        FeedbackRepository $repository,
        FeedbackService $service,
    ): JsonResponse {
        $report = $this->find($repository, $id);
        $raw = json_decode($request->getContent() ?: '{}', true);
        $note = \is_array($raw) && \is_string($raw['note'] ?? null) ? $raw['note'] : null;

        $service->updateByAdmin($report, $payload->category, $payload->body, $note);

        return $this->json(FeedbackPresenter::admin($report));
    }

    #[Route('/api/admin/feedback/{id}', name: 'api_admin_feedback_delete', methods: ['DELETE'], format: 'json')]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
    public function delete(int $id, FeedbackRepository $repository, FeedbackService $service): JsonResponse
    {
        $service->deleteByAdmin($this->find($repository, $id));

        return $this->json(['ok' => true]);
    }

    private function find(FeedbackRepository $repository, int $id): Feedback
    {
        $report = $repository->find($id);
        if (null === $report) {
            throw new NotFoundHttpException('Feedback not found.');
        }

        return $report;
    }

    /**
     * An unknown value degrades to "no filter" rather than a 400 — the same
     * rule the shelf and search endpoints use, so a stale bookmark still works.
     *
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }
}
