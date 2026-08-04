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
use App\Service\Catalog\WorkHydrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class FeedController extends AbstractController
{
    /** following: you and the people you follow. me: only you. everyone: the site. */
    private const SCOPES = ['following', 'everyone', 'me'];

    #[Route('/api/me/feed', name: 'api_me_feed', methods: ['GET'])]
    public function feed(
        Request $request,
        #[CurrentUser] User $user,
        EntryRepository $entryRepository,
        FollowRepository $followRepository,
        ReviewRepository $reviewRepository,
        WorkPresenter $workPresenter,
        WorkHydrator $hydrator,
    ): JsonResponse {
        $scope = $request->query->getString('scope', 'following');
        $limit = min(80, max(1, $request->query->getInt('limit', 40)));
        $page = max(1, $request->query->getInt('page', 1));

        if (!\in_array($scope, self::SCOPES, true)) {
            return $this->json(['error' => 'invalid_scope'], Response::HTTP_BAD_REQUEST);
        }

        $userIds = match ($scope) {
            // Own activity included, as on every timeline that has one: you
            // want to see what you posted sitting where other people will see
            // it. 'me' is the way to read it without them.
            'following' => [...$followRepository->followedIdsOf($user), (int) $user->getId()],
            'me' => [(int) $user->getId()],
            default => null,
        };

        /*
         * One more than asked for, then dropped. It answers "is there another
         * page" without a second COUNT over every entry in the system, which
         * is the only thing the pager needs to know.
         */
        $entries = $entryRepository->findActivity($userIds, $limit + 1, ($page - 1) * $limit);
        $hasMore = \count($entries) > $limit;
        $entries = \array_slice($entries, 0, $limit);

        /*
         * Everything below is arranged so that rendering a row costs no
         * queries. This endpoint was the only listing in the API that skipped
         * both of these steps, and it showed: forty rows meant forty review
         * lookups plus a lazy walk into each work's genres, ratings and
         * credits, all of it after the response had already been decided on.
         */
        $rows = [];
        foreach ($entries as $entry) {
            $work = $entry->getWork();
            $author = $entry->getUser();
            if (null !== $work && null !== $author) {
                $rows[] = ['entry' => $entry, 'work' => $work, 'author' => $author];
            }
        }

        $hydrator->preload(
            array_map(static fn (array $row) => $row['work'], $rows),
            [WorkHydrator::RATINGS],
        );

        $reviews = $reviewRepository->mapForPairs(
            array_map(static fn (array $row) => (int) $row['author']->getId(), $rows),
            array_map(static fn (array $row) => (int) $row['work']->getId(), $rows),
        );

        $activity = [];
        foreach ($rows as $row) {
            $review = $reviews[$row['author']->getId().':'.$row['work']->getId()] ?? null;

            $activity[] = [
                'entry' => EntryPresenter::one($row['entry']),
                'user' => UserPresenter::compact($row['author']),
                // listItem, not one(): a feed row is a poster, a title and a
                // status line. one() also carries the overview, the tagline,
                // the backdrop, the trailer and the full credit list, which was
                // 18KB a row for a card that draws none of it.
                'item' => $workPresenter->listItem($row['work']),
                'review' => null === $review ? null : ReviewPresenter::one($review),
            ];
        }

        return $this->json([
            'scope' => $scope,
            'page' => $page,
            'limit' => $limit,
            'hasMore' => $hasMore,
            'activity' => $activity,
        ]);
    }
}
