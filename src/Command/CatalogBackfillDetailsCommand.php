<?php

namespace App\Command;

use App\Service\Tmdb\TmdbAuthException;
use App\Service\Tmdb\TmdbItemMapper;
use App\Service\Tmdb\TmdbClient;
use App\Service\Tmdb\TmdbRateLimitedException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills in the detail fields for everything crawled before they existed.
 *
 * ---- why this is its own command ---------------------------------------
 *
 * The crawler only ever writes new rows — CatalogWorkPersister::persist() is
 * not an update path, and the nightly run subtracts what is already stored. So
 * nothing already in the catalog would ever gain a country on its own. New
 * titles need none of this; they get it from the mapper the moment they arrive.
 *
 * ---- resumable, because it is long ---------------------------------------
 *
 * 876,000 titles at the client's pace is hours. The cursor is the data itself:
 * every pass asks for works whose countries are still null, most popular first.
 * A run that dies, is killed, or hits a rate limit costs only the rows it was
 * holding — start it again and it picks up where the table left off.
 *
 * Popular first on purpose. If it is stopped halfway, the half that is done is
 * the half anybody searches.
 *
 * A title TMDB has no country for is marked `[]` rather than left null, so the
 * next pass does not fetch it again forever.
 */
#[AsCommand(
    name: 'app:catalog:backfill-details',
    description: 'Fetch the TMDB detail fields older rows were crawled without',
)]
class CatalogBackfillDetailsCommand extends Command
{
    /** Rows per database round trip. Small enough to keep memory flat. */
    private const CHUNK = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TmdbClient $tmdb,
        private readonly TmdbItemMapper $mapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max titles this run', '5000')
            ->addOption('min-popularity', null, InputOption::VALUE_REQUIRED, 'Skip anything below this', '0')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'movie|series, default both')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Requests in flight at once', '8')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Print how much is left and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->entityManager->getConnection();

        $remaining = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM works WHERE deleted_at IS NULL AND countries IS NULL',
        )->fetchOne();

        $done = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM works WHERE deleted_at IS NULL AND countries IS NOT NULL',
        )->fetchOne();

        $io->writeln(sprintf('%s done, %s to go', number_format($done), number_format($remaining)));

        if ($input->getOption('status')) {
            return Command::SUCCESS;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $minPopularity = (float) $input->getOption('min-popularity');
        $type = \in_array($input->getOption('type'), ['movie', 'series'], true)
            ? $input->getOption('type')
            : null;

        // Ceiling of 40, not 20. The client's own limiter allows 300 per ten
        // seconds and we measured 15/s at twenty in flight — the pace was set
        // by how many requests were waiting, not by the limiter, so the cap was
        // the thing costing hours.
        $concurrency = max(1, min(40, (int) $input->getOption('concurrency')));

        $started = microtime(true);
        $seen = 0;
        $found = 0;
        $blank = 0;
        $failed = 0;

        while ($seen < $limit) {
            /*
             * Movies and series are two different id namespaces and two
             * different endpoints, so the source column decides which. This is
             * the join that made the first version of this command wrong: it
             * asked for source = 'tmdb' and silently skipped every series.
             */
            $rows = $this->entityManager->getConnection()->executeQuery(
                "SELECT w.id, w.type, e.external_id AS tmdb_id
                 FROM works w
                 JOIN external_ids e
                   ON e.work_id = w.id
                  AND e.source = CASE WHEN w.type = 'series' THEN 'tmdb_tv' ELSE 'tmdb' END
                 WHERE w.deleted_at IS NULL
                   AND w.countries IS NULL
                   AND COALESCE(w.popularity, 0) >= :pop"
                   .(null === $type ? '' : ' AND w.type = :type')."
                 ORDER BY w.popularity DESC NULLS LAST, w.id ASC
                 LIMIT :chunk",
                array_filter([
                    'pop' => $minPopularity,
                    'type' => $type,
                    'chunk' => min(self::CHUNK, $limit - $seen),
                ], static fn ($value) => null !== $value),
            )->fetchAllAssociative();

            if ([] === $rows) {
                $io->writeln('nothing left to do');
                break;
            }

            foreach (array_chunk($rows, $concurrency) as $window) {
                $requests = [];
                $byTmdbId = [];

                foreach ($window as $row) {
                    $key = $row['type'].':'.$row['tmdb_id'];
                    $requests[$key] = [
                        // No append_to_response: countries are on the base
                        // document, and the credits this crawler usually asks
                        // for would triple the payload for nothing.
                        'path' => ('series' === $row['type'] ? '/tv/' : '/movie/').$row['tmdb_id'],
                        'query' => [],
                    ];
                    $byTmdbId[$key] = $row['id'];
                }

                try {
                    $details = $this->tmdb->getMany($requests);
                } catch (TmdbAuthException $e) {
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                } catch (TmdbRateLimitedException $e) {
                    // Stop cleanly. Nothing is lost: the next run selects on
                    // countries IS NULL and picks up exactly here.
                    $io->warning('Backing off: '.$e->getMessage());
                    break 2;
                }

                foreach ($details as $key => $detail) {
                    ++$seen;
                    $workId = $byTmdbId[$key] ?? null;

                    if (null === $workId) {
                        continue;
                    }

                    if (null === $detail) {
                        ++$failed;
                        continue;
                    }

                    $codes = $this->codesFrom($detail);
                    $extras = $this->mapper->extrasFor($detail);
                    [] === $codes ? ++$blank : ++$found;

                    /*
                     * Every field in one statement. `countries` is what the
                     * next pass selects on, so it is written even when empty —
                     * left null, a title TMDB has no country for would be
                     * re-fetched on every pass forever.
                     */
                    $this->entityManager->getConnection()->executeStatement(
                        'UPDATE works SET
                            countries = :codes,
                            budget = :budget,
                            revenue = :revenue,
                            homepage = :homepage,
                            spoken_languages = :languages,
                            in_production = :production,
                            next_episode_at = :nextAt,
                            episodes_air = :episodes
                         WHERE id = :id',
                        [
                            'codes' => json_encode($codes),
                            'budget' => $extras['budget'],
                            'revenue' => $extras['revenue'],
                            'homepage' => null === $extras['homepage']
                                ? null
                                : mb_substr((string) $extras['homepage'], 0, 500),
                            'languages' => null === $extras['spokenLanguages']
                                ? null
                                : json_encode($extras['spokenLanguages']),
                            'production' => $extras['inProduction'],
                            'nextAt' => $extras['nextEpisodeAt'],
                            'episodes' => null === $extras['episodesAir']
                                ? null
                                : json_encode($extras['episodesAir']),
                            'id' => $workId,
                        ],
                        ['production' => ParameterType::BOOLEAN],
                    );
                }
            }

            $rate = $seen / max(0.001, microtime(true) - $started);
            $io->writeln(sprintf(
                '  %s seen · %s with a country · %s without · %s failed · %.1f/s',
                number_format($seen),
                number_format($found),
                number_format($blank),
                number_format($failed),
                $rate,
            ));
        }

        $left = max(0, $remaining - $seen);
        $io->success(sprintf(
            '%s updated this run, %s still to go',
            number_format($seen),
            number_format($left),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return list<string>
     */
    private function codesFrom(array $detail): array
    {
        $codes = [];

        foreach ((array) ($detail['origin_country'] ?? []) as $code) {
            if (\is_string($code)) {
                $codes[] = strtoupper($code);
            }
        }

        foreach ((array) ($detail['production_countries'] ?? []) as $row) {
            $code = \is_array($row) ? ($row['iso_3166_1'] ?? null) : null;
            if (\is_string($code)) {
                $codes[] = strtoupper($code);
            }
        }

        return array_values(array_unique(array_filter(
            $codes,
            static fn (string $code) => 1 === preg_match('/^[A-Z]{2}$/', $code),
        )));
    }
}
