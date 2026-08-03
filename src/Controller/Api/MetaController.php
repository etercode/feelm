<?php

namespace App\Controller\Api;

use App\Entity\Credit;
use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Work;
use App\Search\SearchCriteria;
use App\Service\Media\ObjectStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What a client needs to know before it asks anything else.
 *
 * The web front end can hardcode that a work is one of four types and a shelf
 * one of four statuses, because it ships with the API and the two move
 * together. A phone does not: it is installed, and then it is out there for
 * years while the server changes underneath it. Anything it hardcodes is
 * something that can only be fixed by an app store release.
 *
 * So the enumerations come from here, from the same constants the API validates
 * against — add a fifth type and every client that reads this learns about it
 * without being rebuilt.
 *
 * `minimumClient` is the other half of that. A client older than this cannot be
 * trusted to understand the responses, and should tell its user to update
 * rather than misbehave quietly. It is a floor, not a current version: raising
 * it locks people out, so it moves only when an old build genuinely breaks.
 */
final class MetaController extends AbstractController
{
    /**
     * The shape of the responses, not the version of the software.
     *
     * Goes up when something already published changes meaning or disappears.
     * Adding a field is not a change under that rule — a client that ignores
     * unknown keys keeps working, and every client should.
     */
    private const CONTRACT = '1.0';

    /** Oldest native build that still understands the above. */
    private const MINIMUM_CLIENT = '1.0.0';

    public function __construct(
        private readonly ObjectStorage $storage,
    ) {
    }

    #[Route('/api/meta', name: 'api_meta', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'contract' => self::CONTRACT,
            'minimumClient' => self::MINIMUM_CLIENT,

            /*
             * Every closed set a client would otherwise hardcode. These are the
             * constants the API itself validates against, so a client reading
             * them cannot disagree with the server about what is valid.
             */
            'types' => Work::TYPES,
            'shelfStatuses' => Entry::STATUSES,
            'sorts' => SearchCriteria::SORTS,
            'creditRoles' => Credit::ROLES,
            'locales' => User::SUPPORTED_LOCALES,
            'defaultLocale' => User::DEFAULT_LOCALE,

            /*
             * Asking for more than the cap does not fail — it is clamped — so a
             * client that does not read this still works. It is here so a client
             * can page efficiently instead of discovering the ceiling by
             * bumping into it.
             */
            'limits' => [
                'pageSize' => SearchCriteria::MAX_LIMIT,
                'searchQuery' => 120,
            ],

            /*
             * Where artwork comes from today. The URLs in a work are already
             * absolute, so nothing has to build them — this is for a client
             * that wants to pin its image loader to known hosts, and it has to
             * be told rather than assume, because artwork is mid-migration from
             * TMDB's CDN to our own bucket and will move again when a CDN goes
             * in front of that.
             */
            'imageHosts' => $this->imageHosts(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function imageHosts(): array
    {
        // TMDB always, because unmirrored artwork still serves from there and
        // will for as long as anything is unmirrored.
        $hosts = ['image.tmdb.org'];

        if ($this->storage->isConfigured()) {
            $host = parse_url($this->storage->url('probe'), \PHP_URL_HOST);
            if (\is_string($host) && '' !== $host) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
