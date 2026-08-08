<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Work;
use App\Presenter\WorkPresenter;
use App\Repository\SeenMarkRepository;
use App\Repository\WorkRepository;
use App\Search\SearchCriteria;
use App\Search\WorkSearch;
use App\Service\Catalog\WorkHydrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Everything the front page draws, in one request.
 *
 * It used to make five: a popularity rail for each of the four types, plus the
 * release queue. Two of those four are empty — the catalog holds no games and
 * no books — so a third of a megabyte and two guaranteed-empty round trips
 * were spent on rails that never rendered, on every cold load.
 *
 * Deciding what to send is the server's job, not the browser's: it is the only
 * one that knows a type has nothing in it. A rail with no titles is left out
 * of the response entirely, and the page draws what it is given.
 */
final class HomeController extends AbstractController
{
    /** Four rows at the widest breakpoint the poster wall uses. */
    private const RAIL = 28;

    private const MAX_RAIL = 40;

    public function __construct(
        private readonly WorkSearch $search,
        private readonly WorkRepository $works,
        private readonly WorkPresenter $presenter,
        private readonly WorkHydrator $hydrator,
    ) {
    }

    #[Route('/api/home', name: 'api_home', methods: ['GET'])]
    public function index(
        Request $request,
        SeenMarkRepository $seenMarks,
        #[CurrentUser] ?User $user = null,
    ): JsonResponse {
        $rail = min(self::MAX_RAIL, max(1, $request->query->getInt('rail', self::RAIL)));
        $upcomingLimit = min(60, max(1, $request->query->getInt('upcoming', 40)));

        /*
         * Everything is gathered before anything is presented, because the NEW
         * badge is decided in the presenter and it needs to know which of *this
         * page's* titles the viewer has already opened — one query for the lot,
         * rather than the browser holding every id it has ever seen.
         */
        $railWorks = [];
        foreach (Work::TYPES as $type) {
            $works = $this->railWorks($type, $rail);
            if ([] !== $works) {
                $railWorks[$type] = $works;
            }
        }

        /*
         * Out in the last three months, most popular first. Sits opposite the
         * release queue: that one is what is coming, this one is what landed
         * while you were not looking, and together they are the reason to open
         * the page more than once.
         */
        $latest = $this->works->findRecentlyReleased($rail);
        $this->hydrator->preload($latest, [WorkHydrator::RATINGS]);

        $upcoming = $this->works->findUpcoming($upcomingLimit);
        // Genres, ratings and the director — what the release plate draws.
        $this->hydrator->preload($upcoming, [
            WorkHydrator::GENRES,
            WorkHydrator::RATINGS,
            WorkHydrator::CREDITS,
        ]);

        // One lookup covering every title on the page, then present.
        $onPage = array_merge($latest, $upcoming, ...array_values($railWorks));
        $ids = array_values(array_filter(array_map(
            static fn (Work $work) => $work->getId(),
            $onPage,
        )));

        $this->presenter->forViewer($user, null === $user ? [] : $seenMarks->seenAmong($user, $ids));

        $rails = [];
        foreach ($railWorks as $type => $works) {
            $rails[] = [
                'type' => $type,
                'items' => array_map(fn (Work $work) => $this->presenter->listItem($work), $works),
            ];
        }

        return $this->json([
            'rails' => $rails,
            'latest' => array_map(fn (Work $work) => $this->presenter->listItem($work), $latest),
            'upcoming' => array_map(fn (Work $work) => $this->presenter->upcoming($work), $upcoming),
        ]);
    }

    /**
     * One type's most popular titles.
     *
     * No total and no facets: the rail is a row of posters with no counter
     * over it, and counting how many of seven hundred thousand rows match is
     * about half the work of listing them.
     *
     * Returns the works rather than the presented rows, so the caller can
     * gather every title on the page before any of them is presented.
     *
     * @return list<Work>
     */
    private function railWorks(string $type, int $limit): array
    {
        $criteria = new SearchCriteria(types: [$type], sort: 'popularity', limit: $limit);

        return $this->search->search($criteria, withSuggestion: false, withTotal: false)['works'];
    }
}
