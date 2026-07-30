<?php

namespace App\Controller\Api;

use App\Dto\UpsertEntryRequest;
use App\Entity\User;
use App\Presenter\EntryPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\EntryRepository;
use App\Repository\WorkRepository;
use App\Service\ShelfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class EntryController extends AbstractController
{
    #[Route('/api/me/entries', name: 'api_me_entries', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        EntryRepository $entryRepository,
        WorkPresenter $workPresenter,
    ): JsonResponse {
        $entries = $entryRepository->findByUser($user);

        return $this->json([
            'entries' => array_map(fn ($e) => [
                'entry' => EntryPresenter::one($e),
                'item' => $workPresenter->one($e->getWork()),
            ], $entries),
        ]);
    }

    #[Route('/api/me/entries/{type}/{slug}', name: 'api_me_entries_upsert', methods: ['PUT'], requirements: ['type' => 'movie|series|game|book'], format: 'json')]
    public function upsert(
        string $type,
        string $slug,
        #[MapRequestPayload] UpsertEntryRequest $payload,
        #[CurrentUser] User $user,
        WorkRepository $workRepository,
        ShelfService $shelfService,
    ): JsonResponse {
        $work = $workRepository->findOneByTypeAndSlug($type, $slug);
        if (null === $work) {
            throw new NotFoundHttpException('Work not found.');
        }

        try {
            if ($payload->clear) {
                $shelfService->upsert($user, $work, ['status' => null]);

                return $this->json(null, Response::HTTP_NO_CONTENT);
            }

            $data = [];
            if (null !== $payload->status) {
                $data['status'] = $payload->status;
            }
            if (null !== $payload->rating) {
                $data['rating'] = $payload->rating;
            }
            if (null !== $payload->progress) {
                $data['progress'] = $payload->progress;
            }

            if ([] === $data) {
                return $this->json(['error' => 'empty_payload'], Response::HTTP_BAD_REQUEST);
            }

            $entry = $shelfService->upsert($user, $work, $data);

            return $this->json(EntryPresenter::one($entry));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/api/me/entries/{type}/{slug}', name: 'api_me_entries_delete', methods: ['DELETE'], requirements: ['type' => 'movie|series|game|book'])]
    public function delete(
        string $type,
        string $slug,
        #[CurrentUser] User $user,
        WorkRepository $workRepository,
        ShelfService $shelfService,
    ): JsonResponse {
        $work = $workRepository->findOneByTypeAndSlug($type, $slug);
        if (null === $work) {
            throw new NotFoundHttpException('Work not found.');
        }

        $shelfService->upsert($user, $work, ['status' => null]);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
