<?php

namespace App\Controller\Api;

use App\Entity\Work;
use App\Presenter\ReviewPresenter;
use App\Presenter\WorkPresenter;
use App\Repository\ReviewRepository;
use App\Repository\WorkRepository;
use App\Search\SearchCriteria;
use App\Search\WorkSearch;
use App\Service\Catalog\WorkHydrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalog resource. Still published under /api/items — that is the shape
 * the frontend reads, and the storage rename does not belong in the URL.
 *
 * Browsing goes through the same search that /api/search uses, so a browse page
 * can filter and page server-side instead of asking for everything and doing it
 * in the browser.
 */
class WorkController extends AbstractController
{
    public function __construct(
        private readonly WorkRepository $works,
        private readonly WorkPresenter $presenter,
        private readonly WorkSearch $search,
    ) {
    }

    #[Route('/api/items', name: 'api_items_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $criteria = SearchCriteria::fromRequest($request);

        // `?total=0` from a caller that does not print the number: it halves
        // the work, and the pager still learns whether a next page exists.
        $withTotal = $request->query->getBoolean('total', true);
        $result = $this->search->search($criteria, withSuggestion: false, withTotal: $withTotal);

        return $this->json([
            'items' => array_map(fn (Work $work) => $this->presenter->listItem($work), $result['works']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'hasMore' => $result['hasMore'],
            'limit' => $result['limit'],
        ]);
    }

    #[Route('/api/upcoming', name: 'api_upcoming', methods: ['GET'])]
    public function upcoming(Request $request, WorkHydrator $hydrator): JsonResponse
    {
        $limit = min(40, max(1, $request->query->getInt('limit', 20)));
        $works = $this->works->findUpcoming($limit);

        /*
         * Without this the presenter walks each work's collections and Doctrine
         * fetches them one work at a time — and one person at a time behind the
         * credits. Twenty titles was 170 queries: twenty for credits, twenty
         * for external ids, and a hundred and thirty asking who each credited
         * person is. Four now.
         *
         * /api/items does not need the call because WorkSearch preloads on its
         * own way out; this endpoint does not go through it.
         */
        // Genres, ratings and the director — not external ids, which the short
        // payload does not carry and which would be a query for nothing.
        $hydrator->preload($works, [WorkHydrator::GENRES, WorkHydrator::RATINGS, WorkHydrator::CREDITS]);

        return $this->json([
            'items' => array_map(fn (Work $work) => $this->presenter->upcoming($work), $works),
        ]);
    }

    #[Route('/api/items/{type}/{slug}', name: 'api_items_show', methods: ['GET'], requirements: ['type' => 'movie|series|game|book'])]
    public function show(string $type, string $slug, ReviewRepository $reviews, WorkHydrator $hydrator): JsonResponse
    {
        $work = $this->requireWork($type, $slug);

        /*
         * One title, but its credits are a collection of collections: the
         * presenter asks each credit who the person is, and Doctrine answers
         * one person at a time. A film with twelve cast members and a director
         * was sixteen queries, thirteen of them the same lookup against
         * `people`. This makes it four.
         */
        $hydrator->preload([$work]);

        return $this->json([
            'item' => $this->presenter->one($work),
            'rating' => $reviews->ratingOf($work),
            /*
             * The rest of the series. This used to be assembled in the browser
             * from whatever the front page had cached, so it appeared for a
             * sequel popular enough to be in a rail and was quietly missing for
             * every other one. One indexed query answers it for all 21,621
             * titles that belong to a collection.
             */
            'collection' => $this->works->collectionSiblings($work),
        ]);
    }

    /**
     * The little that a hover card needs, and nothing else.
     *
     * A poster in a grid carries a title, a year and a score — enough for the
     * card, not enough for the panel that expands out of it, which wants a
     * backdrop, a sentence and a trailer. The obvious answer is to ask
     * /api/items/{type}/{slug}, and that is the wrong one: it returns every
     * credit, and for a series every season and episode. Firing that because
     * somebody's cursor paused over a poster would be megabytes and dozens of
     * joins for a preview they may never look at twice.
     *
     * So: one row, four columns, no relations but genres.
     */
    #[Route('/api/items/{type}/{slug}/preview', name: 'api_items_preview', methods: ['GET'], requirements: ['type' => 'movie|series|game|book'])]
    public function preview(string $type, string $slug): JsonResponse
    {
        $work = $this->requireWork($type, $slug);

        return $this->json([
            'id' => $work->getId(),
            'backdrop' => $this->presenter->backdropUrl($work),
            'overview' => $work->getOverview(),
            'tagline' => $work->getTagline(),
            'trailer' => $work->getTrailer(),
            'genres' => array_slice($work->getGenreNames(), 0, 3),
            'certification' => $work->getCertification(),
        ]);
    }

    /**
     * More like this one.
     *
     * The browser used to do this: ask for 48 titles of the same type, 22 KB
     * of them, and pick whichever shared a genre. That looked at 48 rows out of
     * seven hundred thousand, so "related" meant "also happens to be popular",
     * and it stopped working entirely the moment list rows stopped carrying
     * genres. The same search that powers /search answers it properly, against
     * the whole catalog, and sends nine rows instead of forty-eight.
     *
     * Its own endpoint rather than part of the detail payload: the page can
     * draw everything above it without waiting on this.
     */
    #[Route('/api/items/{type}/{slug}/related', name: 'api_items_related', methods: ['GET'], requirements: ['type' => 'movie|series|game|book'])]
    public function related(string $type, string $slug, Request $request, WorkHydrator $hydrator): JsonResponse
    {
        $work = $this->requireWork($type, $slug);
        $limit = min(24, max(1, $request->query->getInt('limit', 8)));

        /*
         * TMDB's own answer first.
         *
         * The crawl has been storing similar and recommended titles per work
         * all along and nothing read them; the genre search below was standing
         * in for data we already had. It stays as the fallback, because a title
         * the details backfill has not reached has no rows in work_related and
         * "popular in the same genres" beats an empty rail.
         */
        $relatedIds = $this->works->relatedIds((int) $work->getId(), $limit);

        if ([] !== $relatedIds) {
            $byId = [];
            foreach ($this->works->findBy(['id' => $relatedIds]) as $other) {
                $byId[(int) $other->getId()] = $other;
            }

            // TMDB's ranking is the whole value here, and findBy returns rows
            // in whatever order it likes, so the id order is reapplied.
            $ordered = array_values(array_filter(array_map(
                static fn (int $id) => $byId[$id] ?? null,
                $relatedIds,
            )));

            // One query for the scores rather than one per card.
            $hydrator->preloadIds($relatedIds, [WorkHydrator::RATINGS]);

            return $this->json([
                'items' => array_map(fn ($other) => $this->presenter->listItem($other), $ordered),
            ]);
        }

        $genres = $work->getGenreSlugs();

        if ([] === $genres) {
            return $this->json(['items' => []]);
        }

        $criteria = new SearchCriteria(
            types: [$type],
            genres: $genres,
            sort: 'popularity',
            // One extra, because the title itself is the most likely match.
            limit: $limit + 1,
        );
        $result = $this->search->search($criteria, withSuggestion: false, withTotal: false);

        $items = [];
        foreach ($result['works'] as $other) {
            if ($other->getId() === $work->getId()) {
                continue;
            }
            $items[] = $this->presenter->listItem($other);
            if (\count($items) >= $limit) {
                break;
            }
        }

        return $this->json(['items' => $items]);
    }

    #[Route('/api/items/{type}/{slug}/reviews', name: 'api_items_reviews', methods: ['GET'], requirements: ['type' => 'movie|series|game|book'])]
    public function reviews(string $type, string $slug, ReviewRepository $reviews): JsonResponse
    {
        $work = $this->requireWork($type, $slug);

        return $this->json([
            'reviews' => array_map(static fn ($review) => ReviewPresenter::one($review), $reviews->findByWork($work)),
            'rating' => $reviews->ratingOf($work),
        ]);
    }

    private function requireWork(string $type, string $slug): Work
    {
        $work = $this->works->findOneByTypeAndSlug($type, $slug);
        if (null === $work) {
            throw new NotFoundHttpException('No such title.');
        }

        return $work;
    }
}
