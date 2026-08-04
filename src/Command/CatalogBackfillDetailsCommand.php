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
 * every pass asks for works never synced, most popular first.
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
            'SELECT COUNT(*) FROM works WHERE deleted_at IS NULL AND details_synced_at IS NULL',
        )->fetchOne();

        $done = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM works WHERE deleted_at IS NULL AND details_synced_at IS NOT NULL',
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
                   AND w.details_synced_at IS NULL
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
                        'path' => ('series' === $row['type'] ? '/tv/' : '/movie/').$row['tmdb_id'],
                        // Only the four this command is here for. Credits,
                        // videos and release dates are already stored and would
                        // double the response to re-send what we have.
                        'query' => [
                            'append_to_response' => 'watch/providers,keywords,similar,recommendations',
                        ],
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

                        /*
                         * Stamped anyway, or this never finishes.
                         *
                         * These are titles TMDB has deleted — every one of a
                         * sample of eight answered 404. Left unstamped they
                         * stay null, and because the pass takes the most
                         * popular unsynced rows first, the same dead ids come
                         * back to the front of every pass. They were 0% of a
                         * pass at the start, 8% an hour in and 17% an hour
                         * after that: the artwork mirror's exact failure mode,
                         * where a growing set of permanent failures is retried
                         * forever and squeezes out real work.
                         *
                         * A transient failure gets stamped too, which is the
                         * cost. That is what re-syncing on details_synced_at is
                         * for — the same sweep that refreshes stale watch
                         * providers will pick them up.
                         */
                        $this->entityManager->getConnection()->executeStatement(
                            'UPDATE works SET details_synced_at = NOW() WHERE id = :id',
                            ['id' => $workId],
                        );

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
                            details_synced_at = NOW(),
                            budget = :budget,
                            revenue = :revenue,
                            homepage = :homepage,
                            spoken_languages = :languages,
                            watch_providers = :providers,
                            in_production = :production,
                            next_episode_at = :nextAt,
                            episodes_air = :episodes
                         WHERE id = :id',
                        [
                            'budget' => $extras['budget'],
                            'revenue' => $extras['revenue'],
                            'homepage' => null === $extras['homepage']
                                ? null
                                : mb_substr((string) $extras['homepage'], 0, 500),
                            'languages' => null === $extras['spokenLanguages']
                                ? null
                                : json_encode($extras['spokenLanguages']),
                            'providers' => null === $extras['watchProviders']
                                ? null
                                : json_encode($extras['watchProviders']),
                            'production' => $extras['inProduction'],
                            'nextAt' => $extras['nextEpisodeAt'],
                            'episodes' => null === $extras['episodesAir']
                                ? null
                                : json_encode($extras['episodesAir']),
                            'id' => $workId,
                        ],
                        ['production' => ParameterType::BOOLEAN],
                    );

                    $this->writeTags($workId, $codes, $extras);
                    $this->writeRelated($workId, $extras);
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



    /** country, keyword and studio share one table — see the migration. */
    private const TAG_COUNTRY = 1;
    private const TAG_KEYWORD = 2;
    private const TAG_COMPANY = 3;

    /**
     * Replace this work's tags with what TMDB says now.
     *
     * Deleted first rather than merged: the answer arriving is the current one,
     * and a studio or keyword that has been corrected upstream should not
     * survive here because it was true once.
     *
     * @param list<string>         $countries
     * @param array<string, mixed> $extras
     */
    private function writeTags(int $workId, array $countries, array $extras): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM work_tag WHERE work_id = :id', ['id' => $workId]);

        $groups = [
            self::TAG_COUNTRY => $countries,
            self::TAG_KEYWORD => $extras['keywords'] ?? [],
            self::TAG_COMPANY => $extras['companies'] ?? [],
        ];

        /*
         * One statement, not one per tag.
         *
         * A title carries about thirteen of these and twenty-four related
         * rows, and writing them individually meant nearly forty round trips
         * to serve a single HTTP fetch — the backfill ran at 3/s against a
         * client that can fetch at twenty. Batched, it is three statements a
         * title whatever the counts are.
         */
        $rows = [];
        $params = [];

        foreach ($groups as $kind => $values) {
            foreach ((array) $values as $value) {
                $value = mb_substr(trim((string) $value), 0, 120);
                if ('' === $value) {
                    continue;
                }

                $i = \count($rows);
                $rows[] = "(:w{$i}, :k{$i}, :v{$i})";
                $params["w{$i}"] = $workId;
                $params["k{$i}"] = $kind;
                $params["v{$i}"] = $value;
            }
        }

        if ([] === $rows) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO work_tag (work_id, kind, value) VALUES '.implode(', ', $rows)
            .' ON CONFLICT (work_id, kind, value) DO NOTHING',
            $params,
        );
    }

    /**
     * What TMDB says is like this one.
     *
     * Replaced rather than merged: the second call is the current answer, and
     * a row that has dropped out of TMDB's list should drop out of ours.
     *
     * TMDB ids, not ours. The title being pointed at may not be crawled yet,
     * and an id keeps the pointer good for whenever it arrives — the read side
     * resolves through external_ids and simply shows fewer.
     *
     * @param array<string, mixed> $extras
     */
    private function writeRelated(int $workId, array $extras): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('DELETE FROM work_related WHERE work_id = :id', ['id' => $workId]);

        $rows = [];
        $params = [];

        foreach (['similar' => $extras['similar'] ?? null, 'recommended' => $extras['recommended'] ?? null] as $kind => $ids) {
            foreach ((array) $ids as $position => $tmdbId) {
                $i = \count($rows);
                $rows[] = "(:w{$i}, :k{$i}, :t{$i}, :p{$i})";
                $params["w{$i}"] = $workId;
                $params["k{$i}"] = $kind;
                $params["t{$i}"] = (int) $tmdbId;
                $params["p{$i}"] = $position;
            }
        }

        if ([] === $rows) {
            return;
        }

        // Same reason as the tags: one statement rather than twenty-four.
        $connection->executeStatement(
            'INSERT INTO work_related (work_id, kind, tmdb_id, position) VALUES '.implode(', ', $rows)
            .' ON CONFLICT (work_id, kind, tmdb_id) DO NOTHING',
            $params,
        );
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
