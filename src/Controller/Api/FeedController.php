<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Presenter\EntryPresenter;
use App\Presenter\WorkPresenter;
use App\Presenter\ReviewPresenter;
use App\Presenter\UserPresenter;
use App\Repository\EntryRepository;
use App\Repository\FollowRepository;
use App\Repository\ReviewRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class FeedController extends AbstractController
{
    #[Route('/api/me/feed', name: 'api_me_feed', methods: ['GET'])]
    public function feed(
        Request $request,
        #[CurrentUser] User $user,
        EntryRepository $entryRepository,
        FollowRepository $followRepository,
        ReviewRepository $reviewRepository,
        WorkPresenter $workPresenter,
    ): JsonResponse {
        $scope = $request->query->getString('scope', 'following');
        $limit = min(80, max(1, $request->query->getInt('limit', 40)));

        if (!\in_array($scope, ['following', 'everyone'], true)) {
            return $this->json(['error' => 'invalid_scope'], Response::HTTP_BAD_REQUEST);
        }

        $userIds = null;
        if ('following' === $scope) {
            $userIds = $followRepository->followedIdsOf($user);
            // Include own activity in the following feed.
            $userIds[] = (int) $user->getId();
        }

        $entries = $entryRepository->findActivity($userIds, $limit);
        $activity = [];

        foreach ($entries as $entry) {
            $work = $entry->getWork();
            $author = $entry->getUser();
            if (null === $work || null === $author) {
                continue;
            }

            $review = $reviewRepository->findOneByUserAndWork($author, $work);

            $activity[] = [
                'entry' => EntryPresenter::one($entry),
                'user' => UserPresenter::compact($author),
                'item' => $workPresenter->one($work),
                'review' => null === $review ? null : ReviewPresenter::one($review),
            ];
        }

        return $this->json([
            'scope' => $scope,
            'activity' => $activity,
        ]);
    }
}
