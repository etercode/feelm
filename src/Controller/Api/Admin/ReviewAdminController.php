<?php

namespace App\Controller\Api\Admin;

use App\Dto\Admin\AdminReviewRequest;
use App\Entity\Review;
use App\Presenter\ReviewPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\ReviewRepository;
use App\Service\ReviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reviews, for moderation.
 *
 * This is the one part of the admin a moderator exists for, so nothing here
 * asks for ROLE_ADMIN — the firewall's ROLE_MODERATOR floor is the whole
 * check.
 *
 * Editing goes through ReviewService like the author's own edit does, which
 * means the previous wording is kept. Deleting does not: a review and its
 * history go together, and there is no undo.
 */
class ReviewAdminController extends AbstractController
{
    private const MAX_LIMIT = 100;

    private const SORTS = ['recent', 'oldest', 'updated', 'rating', 'lowest'];

    private const TYPES = ['movie', 'series', 'game', 'book'];

    private const RATINGS = ['low', 'mid', 'high'];

    private const EDITED = ['yes', 'no'];

    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly WorkPresenter $workPresenter,
    ) {
    }

    #[Route('/api/admin/reviews', name: 'api_admin_reviews_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 25)));

        $result = $this->reviews->page(
            [
                'q' => $request->query->get('q'),
                'user' => $request->query->get('user') ?: null,
                'type' => $this->oneOf($request->query->get('type'), self::TYPES),
                'rating' => $this->oneOf($request->query->get('rating'), self::RATINGS),
                'edited' => $this->oneOf($request->query->get('edited'), self::EDITED),
                'sort' => $this->oneOf($request->query->get('sort'), self::SORTS),
            ],
            ($page - 1) * $limit,
            $limit,
        );

        // Counted for the whole page at once; the versions collection is lazy,
        // so asking each row for its own would be a query per review.
        $counts = $this->reviews->versionCountsFor(array_map(
            static fn (Review $review) => (int) $review->getId(),
            $result['items'],
        ));

        return $this->json([
            'items' => array_map(
                fn (Review $review) => ReviewPresenter::adminRow(
                    $review,
                    $review->getWork() ? $this->workPresenter->compact($review->getWork()) : null,
                    $counts[$review->getId()] ?? 0,
                ),
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
        ]);
    }

    #[Route('/api/admin/reviews/{id}', name: 'api_admin_reviews_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->present($this->mustFind($id)));
    }

    #[Route('/api/admin/reviews/{id}', name: 'api_admin_reviews_update', methods: ['PATCH'], requirements: ['id' => '\d+'], format: 'json')]
    public function update(
        int $id,
        #[MapRequestPayload] AdminReviewRequest $payload,
        ReviewService $reviews,
    ): JsonResponse {
        $review = $this->mustFind($id);

        // Both fields optional means an empty body parses fine and would
        // otherwise report a successful edit that edited nothing.
        if (null === $payload->rating && null === $payload->body) {
            return $this->json(['error' => 'empty_payload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $reviews->rewrite(
                $review,
                $payload->rating ?? $review->getRating(),
                $payload->body ?? (string) $review->getBody(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->present($review));
    }

    #[Route('/api/admin/reviews/{id}', name: 'api_admin_reviews_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, ReviewService $reviews): JsonResponse
    {
        $reviews->remove($this->mustFind($id));

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /* ------------------------------------------------------------- private */

    /**
     * @return array<string, mixed>
     */
    private function present(Review $review): array
    {
        $work = $review->getWork();

        return ReviewPresenter::admin($review, $work ? $this->workPresenter->compact($work) : null);
    }

    private function mustFind(int $id): Review
    {
        $review = $this->reviews->find($id);
        if (null === $review) {
            throw new NotFoundHttpException('Review not found.');
        }

        return $review;
    }

    /**
     * Anything not on the list is treated as "no filter" rather than as an
     * error — a stale bookmark should show a table, not a 400.
     *
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }
}
