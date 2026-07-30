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
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ProfileController extends AbstractController
{
    #[Route('/api/users/{username}', name: 'api_users_show', methods: ['GET'])]
    public function show(
        string $username,
        UserRepository $userRepository,
        EntryRepository $entryRepository,
        ReviewRepository $reviewRepository,
        FollowRepository $followRepository,
        WorkPresenter $workPresenter,
        #[CurrentUser] ?User $viewer = null,
    ): JsonResponse {
        $user = $userRepository->findOneActiveByUsername($username);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $entries = $entryRepository->findByUser($user);
        $reviews = $reviewRepository->findByUser($user);
        $stats = $entryRepository->statsForUser($user);
        $stats['reviews'] = $reviewRepository->countByUser($user);

        $payload = [
            'user' => UserPresenter::one($user),
            'stats' => $stats,
            'entries' => array_map(fn ($e) => [
                'entry' => EntryPresenter::one($e),
                'item' => $workPresenter->one($e->getWork()),
            ], $entries),
            'reviews' => array_map(static fn ($r) => ReviewPresenter::one($r), $reviews),
            'followersCount' => \count($followRepository->findFollowers($user)),
            'followingCount' => \count($followRepository->findFollowing($user)),
        ];

        if (null !== $viewer && $viewer->getId() !== $user->getId()) {
            $payload['isFollowing'] = $followRepository->isFollowing($viewer, $user);
            $payload['shared'] = $this->sharedItems($viewer, $user, $entryRepository, $workPresenter);
        }

        return $this->json($payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sharedItems(
        User $viewer,
        User $other,
        EntryRepository $entryRepository,
        WorkPresenter $workPresenter,
    ): array {
        $mine = [];
        foreach ($entryRepository->findByUser($viewer) as $entry) {
            $itemId = $entry->getWork()?->getId();
            if (null !== $itemId) {
                $mine[$itemId] = $entry;
            }
        }

        $shared = [];
        foreach ($entryRepository->findByUser($other) as $entry) {
            $work = $entry->getWork();
            $itemId = $work?->getId();
            if (null === $itemId || !isset($mine[$itemId])) {
                continue;
            }
            $shared[] = [
                'item' => $workPresenter->one($work),
                'mine' => EntryPresenter::one($mine[$itemId]),
                'theirs' => EntryPresenter::one($entry),
            ];
        }

        return $shared;
    }
}
