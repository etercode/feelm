<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Presenter\EntryPresenter;
use App\Presenter\ReviewPresenter;
use App\Presenter\UserPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\EntryRepository;
use App\Repository\FollowRepository;
use App\Repository\ReviewRepository;
use App\Repository\UserRepository;
use App\Service\Catalog\WorkHydrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The head of a profile: who somebody is, what they add up to, and the few
 * things worth showing before anyone scrolls.
 *
 * The shelf itself is not here — see ShelfController. This response has to stay
 * a fixed size however much a person has logged, so everything in it is either
 * a count or an explicitly limited list.
 */
class ProfileController extends AbstractController
{
    /** How many titles the "in the middle of" rail shows. */
    private const CURRENT = 12;

    /**
     * Reviews carried inline. The tab's own count comes from stats, so this is
     * only how many arrive without asking again — enough to fill the tab, and
     * bounded, which is the point of this response.
     */
    private const REVIEWS = 20;

    #[Route('/api/users/{username}', name: 'api_users_show', methods: ['GET'])]
    public function show(
        string $username,
        UserRepository $userRepository,
        EntryRepository $entryRepository,
        ReviewRepository $reviewRepository,
        FollowRepository $followRepository,
        WorkPresenter $workPresenter,
        WorkHydrator $hydrator,
        #[CurrentUser] ?User $viewer = null,
    ): JsonResponse {
        $user = $userRepository->findOneActiveByUsername($username);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $stats = $entryRepository->statsForUser($user);
        $stats['reviews'] = $reviewRepository->countByUser($user);

        $current = $entryRepository->findActiveForUser($user, self::CURRENT);
        $banner = $entryRepository->findBannerWork($user);

        $hydrator->preload(array_values(array_filter([
            ...array_map(static fn ($entry) => $entry->getWork(), $current),
            $banner,
        ])));

        $payload = [
            'user' => UserPresenter::one($user),
            'stats' => $stats,
            'current' => array_map(static fn ($entry) => [
                'entry' => EntryPresenter::one($entry),
                // Cards again — the banner below is the one thing here that
                // needs a full work, because it is the only thing drawing a
                // backdrop.
                'item' => $workPresenter->listItem($entry->getWork()),
            ], $current),
            'banner' => null === $banner ? null : $workPresenter->one($banner),
            'reviews' => array_map(
                static fn ($r) => ReviewPresenter::one($r),
                $reviewRepository->findByUser($user, self::REVIEWS),
            ),
            'followersCount' => \count($followRepository->findFollowers($user)),
            'followingCount' => \count($followRepository->findFollowing($user)),
        ];

        if (null !== $viewer && $viewer->getId() !== $user->getId()) {
            $payload['isFollowing'] = $followRepository->isFollowing($viewer, $user);
            // A count, not the titles: what the page shows is "12 in common".
            $payload['sharedCount'] = $entryRepository->countShared($viewer, $user);
        }

        return $this->json($payload);
    }
}
