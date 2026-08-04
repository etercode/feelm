<?php

namespace App\Controller\Api;

use App\Dto\UpsertEntryRequest;
use App\Entity\User;
use App\Presenter\EntryPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\EntryRepository;
use App\Repository\WorkRepository;
use App\Service\Catalog\WorkHydrator;
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
    /**
     * How many titles come back with the shelf.
     *
     * The shelf itself is every row, because the browser answers "is this on my
     * shelf" from it. The titles are only wanted for the handful of recently
     * touched rows anything actually draws, so they are capped rather than sent
     * three thousand at a time.
     */
    private const RECENT_ITEMS = 60;

    /**
     * The signed-in person's whole shelf, and the titles for the recent end of
     * it.
     *
     * ---- what went wrong here ---------------------------------------------
     *
     * This used to be every entry, each presented with `one()` — the full
     * detail-page payload, eighteen kilobytes a row — and no preload, so every
     * row also lazily walked its work's genres, ratings and credits. On a shelf
     * of three thousand that is a 70MB response built out of three thousand
     * fully hydrated Work objects, and production answered it with
     * `Allowed memory size of 268435456 bytes exhausted`.
     *
     * The shelf is still complete, because a partial one would make a poster
     * card lie about whether it is on it. It is just no longer carrying a
     * detail page per row: state is six scalars, and the titles are the sixty
     * most recent, which is more than anything on screen has ever needed.
     */
    #[Route('/api/me/entries', name: 'api_me_entries', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        EntryRepository $entryRepository,
        WorkPresenter $workPresenter,
        WorkHydrator $hydrator,
    ): JsonResponse {
        $state = $entryRepository->shelfStateForUser($user);

        $recent = $entryRepository->pageForUser($user, [], 0, self::RECENT_ITEMS);
        $works = array_map(static fn ($entry) => $entry->getWork(), $recent['items']);
        $hydrator->preload($works, [WorkHydrator::RATINGS]);

        $userId = $user->getId();

        return $this->json([
            'entries' => array_map(static fn (array $row) => [
                'id' => (int) $row['id'],
                'userId' => $userId,
                'itemId' => (int) $row['itemId'],
                'status' => $row['status'],
                'rating' => $row['rating'],
                'progress' => $row['progress'],
                'at' => $row['updatedAt']->format('Y-m-d'),
                'updatedAt' => $row['updatedAt']->format(\DateTimeInterface::ATOM),
            ], $state),
            'items' => array_map(
                static fn ($work) => $workPresenter->listItem($work),
                array_filter($works),
            ),
            'total' => \count($state),
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
