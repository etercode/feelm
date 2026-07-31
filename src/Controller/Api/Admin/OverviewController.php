<?php

namespace App\Controller\Api\Admin;

use App\Entity\Review;
use App\Entity\User;
use App\Presenter\ReviewPresenter;
use App\Presenter\UserPresenter;
use App\Repository\ReviewRepository;
use App\Repository\UserRepository;
use App\Service\Admin\AdminMetrics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The landing page of the admin: how much of everything there is, and what
 * happened lately.
 *
 * Everything here is a count or a short list. Nothing is paged, nothing is
 * filtered, and nothing grows with the size of the catalog — this endpoint has
 * to stay cheap enough that a dashboard can poll it.
 */
class OverviewController extends AbstractController
{
    public function __construct(
        private readonly AdminMetrics $metrics,
        private readonly UserRepository $users,
        private readonly ReviewRepository $reviews,
    ) {
    }

    #[Route('/api/admin/overview', name: 'api_admin_overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return $this->json([
            'totals' => $this->metrics->totals(),
            'users' => $this->users->counts(),
            'recent' => $this->metrics->recentActivity(),
            'newestUsers' => array_map(
                static fn (User $user) => UserPresenter::admin($user),
                $this->users->findRecent(6),
            ),
            'newestReviews' => array_map(
                static fn (Review $review) => ReviewPresenter::one($review),
                $this->reviews->findRecent(6),
            ),
        ]);
    }
}
