<?php

namespace App\Controller\Api;

use App\Entity\Follow;
use App\Entity\User;
use App\Presenter\UserPresenter;
use App\Repository\FollowRepository;
use App\Repository\UserRepository;
use App\Service\Notify\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class FollowController extends AbstractController
{
    #[Route('/api/me/follows/{username}', name: 'api_me_follows_toggle', methods: ['POST'])]
    public function toggle(
        string $username,
        #[CurrentUser] User $user,
        UserRepository $userRepository,
        FollowRepository $followRepository,
        EntityManagerInterface $entityManager,
        PushNotifier $push,
    ): JsonResponse {
        $target = $userRepository->findOneActiveByUsername($username);
        if (null === $target) {
            throw new NotFoundHttpException('User not found.');
        }

        if ($target->getId() === $user->getId()) {
            return $this->json(['error' => 'cannot_follow_self'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $followRepository->findOnePair($user, $target);
        if (null !== $existing) {
            $entityManager->remove($existing);
            $entityManager->flush();

            return $this->json(['following' => false]);
        }

        $follow = (new Follow())->setFollower($user)->setFollowed($target);
        $entityManager->persist($follow);
        $entityManager->flush();

        // After the flush: a push about a follow that failed to save would be
        // the one notification guaranteed to be wrong.
        $push->followed($target, $user);

        return $this->json(['following' => true]);
    }

    #[Route('/api/users/{username}/followers', name: 'api_users_followers', methods: ['GET'])]
    public function followers(string $username, UserRepository $userRepository, FollowRepository $followRepository): JsonResponse
    {
        $user = $userRepository->findOneActiveByUsername($username);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $follows = $followRepository->findFollowers($user);

        return $this->json([
            'users' => array_map(
                static fn (Follow $f) => UserPresenter::one($f->getFollower()),
                $follows,
            ),
        ]);
    }

    #[Route('/api/users/{username}/following', name: 'api_users_following', methods: ['GET'])]
    public function following(string $username, UserRepository $userRepository, FollowRepository $followRepository): JsonResponse
    {
        $user = $userRepository->findOneActiveByUsername($username);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $follows = $followRepository->findFollowing($user);

        return $this->json([
            'users' => array_map(
                static fn (Follow $f) => UserPresenter::one($f->getFollowed()),
                $follows,
            ),
        ]);
    }
}
