<?php

namespace App\Controller\Api;

use App\Presenter\WorkPresenter;
use App\Repository\GenreRepository;
use App\Repository\PersonRepository;
use App\Repository\WorkRepository;
use App\Search\SearchCriteria;
use App\Search\WorkSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Catalog search: full text, the whole filter set, facet counts for the panel,
 * and a "did you mean" correction when a query barely matches.
 */
class SearchController extends AbstractController
{
    /**
     * How long the filter panel's contents are served from cache.
     *
     * Every one of those lists is an aggregate over the whole works table —
     * two of them a GROUP BY across 955,000 rows — and together they measured
     * 1.64s on production. The search page fetches them in parallel with the
     * search itself, so they were what the page waited on: the results came
     * back in 425ms and the page took 2.5 seconds.
     *
     * An hour, because the answers only change when the crawl adds a title,
     * and it runs once a night. A new certification appearing an hour late in
     * a filter dropdown is not a thing anybody can notice.
     */
    private const FILTERS_CACHE_SECONDS = 3600;

    public function __construct(
        private readonly WorkSearch $search,
        private readonly WorkPresenter $presenter,
        private readonly WorkRepository $works,
        private readonly GenreRepository $genres,
        private readonly PersonRepository $people,
    ) {
    }

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $criteria = SearchCriteria::fromRequest($request);
        $withTotal = $request->query->getBoolean('total', true);
        $result = $this->search->search($criteria, withTotal: $withTotal);

        $payload = [
            'query' => $criteria->query,
            'criteria' => $criteria->toArray(),
            'items' => array_map(fn ($work) => $this->presenter->listItem($work), $result['works']),
            'total' => $result['total'],
            'totalIsExact' => $result['totalIsExact'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'hasMore' => $result['hasMore'],
            'limit' => $result['limit'],
            'matched' => $result['matched'],
            'suggestion' => $result['suggestion'],
        ];

        // The panel only needs recounting when someone asks for it.
        if ($request->query->getBoolean('facets', true)) {
            $payload['facets'] = $this->search->facets($criteria);
        }

        return $this->json($payload);
    }

    /**
     * Type-ahead for the search overlay: a handful of works plus the
     * correction, without the facet queries.
     */
    #[Route('/api/search/suggest', name: 'api_search_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $criteria = SearchCriteria::fromRequest($request);
        if (!$criteria->hasQuery()) {
            return $this->json(['items' => [], 'suggestion' => null, 'people' => []]);
        }

        $result = $this->search->search($criteria);

        return $this->json([
            'query' => $criteria->query,
            // The overlay draws a poster and a title. See suggestion().
            'items' => array_map(fn ($work) => $this->presenter->suggestion($work), $result['works']),
            'total' => $result['total'],
            'totalIsExact' => $result['totalIsExact'],
            'suggestion' => $result['suggestion'],
            'people' => array_map(static fn ($person) => [
                'slug' => $person->getSlug(),
                'name' => $person->getName(),
            ], $this->people->searchByName((string) $criteria->query, 4)),
        ]);
    }

    /**
     * What the filter panel needs before anything is typed: the genre list, the
     * certifications and languages the catalog actually contains, and the year
     * range it covers.
     */
    #[Route('/api/search/filters', name: 'api_search_filters', methods: ['GET'])]
    public function filters(CacheInterface $cache): JsonResponse
    {
        return $this->json($cache->get(
            'search.filters',
            function (ItemInterface $item): array {
                $item->expiresAfter(self::FILTERS_CACHE_SECONDS);

                return $this->buildFilters();
            },
            // No early expiration: recomputing is the entire cost, and one
            // reader is not a herd to protect against. See CrawlController.
            0.0,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(): array
    {
        return [
            'genres' => array_map(static fn ($genre) => [
                'slug' => $genre->getSlug(),
                'name' => $genre->getName(),
            ], $this->genres->allSorted()),
            'certifications' => $this->works->certifications(),
            'languages' => $this->works->languages(),
            'years' => $this->works->yearBounds(),
            // Only the decades that actually hold something — see decades().
            'decades' => $this->works->decades(),
            'sorts' => SearchCriteria::SORTS,
        ];
    }
}
