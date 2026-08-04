<?php

namespace App\Controller\Api;

use App\Presenter\EntryPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\EntryRepository;
use App\Repository\UserRepository;
use App\Service\Catalog\WorkHydrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The front of somebody's profile: five short shelves and a summary of taste.
 *
 * One endpoint rather than five calls to /entries with different filters. The
 * queries themselves are cheap either way, but hydrating a work — its genres,
 * its ratings, its credits — is not, and doing it once for all sixty titles is
 * the whole reason this exists. Five separate requests would run five preload
 * passes and fetch the same genre rows over and over.
 *
 * What the shelves are is a product decision rather than a technical one. Two
 * people read this page: whoever owns it, who wants to know what they are in
 * the middle of, and everybody else, who wants to know what this person
 * watched and which of it they rated highly. Those are the first three rails.
 */
class ProfileOverviewController extends AbstractController
{
    /** How many titles a rail carries. Enough to scroll, short enough to scan. */
    private const RAIL = 12;

    /**
     * Each rail is the shelf query with different filters.
     *
     * "loved" is `done` sorted by rating, which the repository already puts
     * nulls-last on — so the top of that list is the highest scores this person
     * has given, which is the question a visitor came to ask.
     *
     * @var array<string, array{status: string, sort: string}>
     */
    private const RAILS = [
        'watching' => ['status' => 'active', 'sort' => 'recent'],
        'loved' => ['status' => 'done', 'sort' => 'rating'],
        'finished' => ['status' => 'done', 'sort' => 'recent'],
        'wishlist' => ['status' => 'wishlist', 'sort' => 'recent'],
        'dropped' => ['status' => 'dropped', 'sort' => 'recent'],
    ];

    #[Route('/api/users/{username}/overview', name: 'api_users_overview', methods: ['GET'])]
    public function show(
        string $username,
        UserRepository $userRepository,
        EntryRepository $entryRepository,
        WorkPresenter $workPresenter,
        WorkHydrator $hydrator,
    ): JsonResponse {
        $user = $userRepository->findOneActiveByUsername($username);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $results = [];
        $works = [];

        foreach (self::RAILS as $key => $filters) {
            $results[$key] = $entryRepository->pageForUser($user, $filters, 0, self::RAIL);

            foreach ($results[$key]['items'] as $entry) {
                $works[] = $entry->getWork();
            }
        }

        // The one pass this endpoint exists for.
        $hydrator->preload($works);

        $rails = [];
        foreach ($results as $key => $result) {
            $rails[$key] = [
                // listItem, not one(): a rail draws a poster, a title and a
                // fact line. one() carries the overview, the cast and the
                // screenshots as well, which was a megabyte of detail-page
                // payload for sixty cards that show none of it.
                'items' => array_map(static fn ($entry) => [
                    'entry' => EntryPresenter::one($entry),
                    'item' => $workPresenter->listItem($entry->getWork()),
                ], $result['items']),
                // The true size of the shelf, not of the rail — it is what the
                // "see all" link is offering to show.
                'total' => $result['total'],
            ];
        }

        return $this->json([
            'rails' => $rails,
            'highlights' => $entryRepository->highlightsForUser($user),
            'taste' => $entryRepository->tasteForUser($user),
        ]);
    }
}
