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
use Symfony\Component\HttpFoundation\Request;
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

    /**
     * Finding somebody to follow.
     *
     * Declared before /api/users/{username} on purpose: Symfony matches in
     * declaration order, and "search" is a perfectly good username as far as
     * that placeholder is concerned.
     */
    #[Route('/api/users/search', name: 'api_users_search', methods: ['GET'])]
    public function search(
        Request $request,
        UserRepository $userRepository,
        FollowRepository $followRepository,
        #[CurrentUser] ?User $viewer = null,
    ): JsonResponse {
        $term = trim((string) $request->query->get('q', ''));

        // Two characters, or every account comes back on the first keystroke.
        if (mb_strlen($term) < 2) {
            return $this->json(['users' => []]);
        }

        $limit = min(20, max(1, $request->query->getInt('limit', 8)));
        $found = $userRepository->searchByName($term, $limit, $viewer);

        /*
         * Whether you already follow each one, so the row can draw the right
         * button without a request per result. One query for the page.
         */
        $following = [];
        if (null !== $viewer && [] !== $found) {
            foreach ($followRepository->followedIdsAmong($viewer, array_map(
                static fn (User $user) => (int) $user->getId(),
                $found,
            )) as $id) {
                $following[$id] = true;
            }
        }

        return $this->json([
            'users' => array_map(static fn (User $user) => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'name' => $user->getName(),
                'avatar' => $user->getAvatar(),
                'tagline' => $user->getTagline(),
                'following' => isset($following[(int) $user->getId()]),
            ], $found),
        ]);
    }

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
