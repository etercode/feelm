<?php

namespace App\Controller\Api;

use App\Dto\SaveReviewRequest;
use App\Entity\User;
use App\Presenter\ReviewPresenter;
use App\Repository\WorkRepository;
use App\Service\ReviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ReviewController extends AbstractController
{
    #[Route('/api/me/reviews/{type}/{slug}', name: 'api_me_reviews_save', methods: ['PUT'], requirements: ['type' => 'movie|series|game|book'], format: 'json')]
    public function save(
        string $type,
        string $slug,
        #[MapRequestPayload] SaveReviewRequest $payload,
        #[CurrentUser] User $user,
        WorkRepository $workRepository,
        ReviewService $reviewService,
    ): JsonResponse {
        $work = $workRepository->findOneByTypeAndSlug($type, $slug);
        if (null === $work) {
            throw new NotFoundHttpException('Work not found.');
        }

        try {
            $review = $reviewService->save($user, $work, $payload->rating, $payload->body);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(ReviewPresenter::one($review));
    }

    #[Route('/api/me/reviews/{type}/{slug}', name: 'api_me_reviews_delete', methods: ['DELETE'], requirements: ['type' => 'movie|series|game|book'])]
    public function delete(
        string $type,
        string $slug,
        #[CurrentUser] User $user,
        WorkRepository $workRepository,
        ReviewService $reviewService,
    ): JsonResponse {
        $work = $workRepository->findOneByTypeAndSlug($type, $slug);
        if (null === $work) {
            throw new NotFoundHttpException('Work not found.');
        }

        if (!$reviewService->delete($user, $work)) {
            throw new NotFoundHttpException('Review not found.');
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
