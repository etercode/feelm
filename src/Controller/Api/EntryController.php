<?php

namespace App\Controller\Api;

use App\Dto\UpsertEntryRequest;
use App\Entity\User;
use App\Entity\Work;
use App\Presenter\EntryPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\EntryRepository;
use App\Repository\SeenMarkRepository;
use App\Repository\WorkRepository;
use App\Service\Catalog\WorkHydrator;
use App\Service\ShelfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
     * What the people you follow are watching, that you have not settled.
     *
     * Its own endpoint rather than part of /api/home: it is the one section of
     * that page that depends on who you are, and folding it in would make the
     * whole payload unshareable between viewers. The page draws everything else
     * without waiting for this.
     */
    /**
     * The viewer's own state for a specific set of titles.
     *
     * For the pages the server renders without knowing who is asking. Browse
     * and search fetch through SvelteKit's `load`, which has no access to the
     * browser's token, so their rows arrive without `viewerEntry` or `isNew` —
     * this fills them in afterwards, for the ids actually on screen.
     *
     * POST because the id list is the request and a hundred of them do not
     * belong in a URL. It reads nothing and writes nothing.
     */
    #[Route('/api/me/state', name: 'api_me_state', methods: ['POST'], format: 'json')]
    public function state(
        Request $request,
        #[CurrentUser] User $user,
        EntryRepository $entryRepository,
        SeenMarkRepository $seenMarks,
    ): JsonResponse {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $ids = \is_array($payload) && \is_array($payload['ids'] ?? null) ? $payload['ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        // Bounded by what a page can draw, not by what a caller asks for.
        $ids = \array_slice($ids, 0, 200);

        if ([] === $ids) {
            return $this->json(['entries' => [], 'seen' => []]);
        }

        return $this->json([
            'entries' => $entryRepository->forWorks($user, $ids),
            'seen' => $seenMarks->seenAmong($user, $ids),
        ]);
    }

    #[Route('/api/me/suggestions', name: 'api_me_suggestions', methods: ['GET'])]
    public function suggestions(
        Request $request,
        #[CurrentUser] User $user,
        EntryRepository $entryRepository,
        WorkRepository $workRepository,
        WorkPresenter $workPresenter,
        WorkHydrator $hydrator,
    ): JsonResponse {
        $limit = min(40, max(1, $request->query->getInt('limit', 20)));
        $ids = $entryRepository->suggestionsFromFriends($user, $limit);

        if ([] === $ids) {
            return $this->json(['items' => []]);
        }

        $works = $workRepository->findBy(['id' => $ids]);
        $hydrator->preload($works, [WorkHydrator::RATINGS]);
        $workPresenter->forViewer($user, $works);

        // findBy answers in whatever order it likes, and the shuffle above is
        // the whole point of the ordering — so it is reapplied here.
        $byId = [];
        foreach ($works as $work) {
            $byId[(int) $work->getId()] = $work;
        }

        return $this->json([
            'items' => array_values(array_map(
                static fn (Work $work) => $workPresenter->listItem($work),
                array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)),
            )),
        ]);
    }

    /**
     * The signed-in person's whole shelf, and the titles for the recent end of
     * it.
     *
     * No longer fetched on every page load — the viewer's row for a title now
     * travels with the title, so this is the shelf page's endpoint rather than
     * the whole application's. See EntryRepository::forWorks.
     *
     * It also used to present every entry with `one()`, the full detail-page
     * payload at eighteen kilobytes a row, with no preload — so each row also
     * lazily walked its work's genres, ratings and credits. On a shelf of three
     * thousand that was a 70MB response and `Allowed memory size of 268435456
     * bytes exhausted` in production. State is six scalars now, and the titles
     * are the sixty most recent.
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
