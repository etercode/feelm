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
        $result = $this->search->search($criteria, withSuggestion: false);

        return $this->json([
            'items' => array_map(fn (Work $work) => $this->presenter->one($work), $result['works']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
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
        $hydrator->preload($works);

        return $this->json([
            'items' => array_map(fn (Work $work) => $this->presenter->upcoming($work), $works),
        ]);
    }

    #[Route('/api/items/{type}/{slug}', name: 'api_items_show', methods: ['GET'], requirements: ['type' => 'movie|series|game|book'])]
    public function show(string $type, string $slug, ReviewRepository $reviews): JsonResponse
    {
        $work = $this->requireWork($type, $slug);

        return $this->json([
            'item' => $this->presenter->one($work),
            'rating' => $reviews->ratingOf($work),
        ]);
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
