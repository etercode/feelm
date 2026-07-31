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

/**
 * Catalog search: full text, the whole filter set, facet counts for the panel,
 * and a "did you mean" correction when a query barely matches.
 */
class SearchController extends AbstractController
{
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
        $result = $this->search->search($criteria);

        $payload = [
            'query' => $criteria->query,
            'criteria' => $criteria->toArray(),
            'items' => array_map(fn ($work) => $this->presenter->one($work), $result['works']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
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
            'items' => array_map(fn ($work) => $this->presenter->one($work), $result['works']),
            'total' => $result['total'],
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
    public function filters(): JsonResponse
    {
        return $this->json([
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
        ]);
    }
}
