<?php

namespace App\Controller\Api;

use App\Entity\SeenMark;
use App\Entity\User;
use App\Repository\SeenMarkRepository;
use App\Repository\WorkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What a person has already looked at.
 *
 * Two pieces: `seen_up_to` says when they last caught up wholesale, and
 * seen_marks records the individual titles they have opened since. A NEW badge
 * is "crawled after seen_up_to and not in seen_marks", which is why catching up
 * is one timestamp write instead of a row per work in the catalog.
 */
class SeenController extends AbstractController
{
    #[Route('/api/me/seen/{type}/{slug}', name: 'api_me_seen_item', methods: ['POST'], requirements: ['type' => 'movie|series|game|book'])]
    public function markItem(
        string $type,
        string $slug,
        #[CurrentUser] User $user,
        WorkRepository $works,
        SeenMarkRepository $seenMarks,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $work = $works->findOneByTypeAndSlug($type, $slug);
        if (null === $work) {
            throw new NotFoundHttpException('No such title.');
        }

        if (null === $seenMarks->findOneByUserAndWork($user, $work)) {
            $entityManager->persist((new SeenMark())->setUser($user)->setWork($work));
            $entityManager->flush();
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/api/me/seen/catch-up', name: 'api_me_seen_catch_up', methods: ['POST'])]
    public function catchUp(
        #[CurrentUser] User $user,
        SeenMarkRepository $seenMarks,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $now = new \DateTimeImmutable();

        // The individual marks are subsumed by the timestamp, so drop them.
        $seenMarks->deleteAllFor($user);
        $user->setSeenUpTo($now);
        $entityManager->flush();

        return $this->json([
            'ok' => true,
            'seenUpTo' => $now->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/api/me/seen', name: 'api_me_seen_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, SeenMarkRepository $seenMarks): JsonResponse
    {
        return $this->json([
            'itemIds' => $seenMarks->seenWorkIdsFor($user),
            'seenUpTo' => $user->getSeenUpTo()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
