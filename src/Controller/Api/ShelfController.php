<?php

namespace App\Controller\Api;

use App\Presenter\EntryPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\EntryRepository;
use App\Repository\UserRepository;
use App\Service\Catalog\WorkHydrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One page of somebody's shelf.
 *
 * Split out of the profile payload, which used to carry every entry a person
 * had. That is fine for a demo account with thirty and several megabytes for
 * somebody with four thousand — and the filtering was happening in the browser,
 * which meant downloading all four thousand to show twenty-four of them.
 */
class ShelfController extends AbstractController
{
    private const TYPES = ['movie', 'series', 'game', 'book'];

    private const STATUSES = ['wishlist', 'active', 'done', 'dropped'];

    private const SORTS = ['recent', 'title', 'rating', 'year'];

    private const MAX_LIMIT = 60;

    #[Route('/api/users/{username}/entries', name: 'api_users_entries', methods: ['GET'])]
    public function index(
        string $username,
        Request $request,
        UserRepository $userRepository,
        EntryRepository $entryRepository,
        WorkPresenter $workPresenter,
        WorkHydrator $hydrator,
    ): JsonResponse {
        $user = $userRepository->findOneActiveByUsername($username);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 24)));

        $result = $entryRepository->pageForUser(
            $user,
            [
                'type' => $this->oneOf($request->query->get('type'), self::TYPES),
                'status' => $this->oneOf($request->query->get('status'), self::STATUSES),
                'q' => $request->query->get('q'),
                'sort' => $this->oneOf($request->query->get('sort'), self::SORTS),
            ],
            ($page - 1) * $limit,
            $limit,
        );

        // Without this the presenter walks each work's genres, ratings and
        // credits one row at a time.
        $hydrator->preload(array_map(static fn ($entry) => $entry->getWork(), $result['items']));

        return $this->json([
            'items' => array_map(static fn ($entry) => [
                'entry' => EntryPresenter::one($entry),
                // A shelf draws poster cards. listItem is what a poster card
                // needs; one() adds the overview, the cast and the trailer to
                // every row of a sixty-row page.
                'item' => $workPresenter->listItem($entry->getWork()),
            ], $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
        ]);
    }

    /**
     * Anything not on the list is treated as "no filter" rather than as an
     * error — a stale bookmark should show a shelf, not a 400.
     *
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }
}
