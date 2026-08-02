<?php

namespace App\Command;

use App\Repository\WorkRepository;
use App\Service\Catalog\CatalogWorkPersister;
use App\Service\Tmdb\TmdbMediaStore;
use App\Service\Tmdb\TmdbChanges;
use App\Service\Tmdb\TmdbClient;
use App\Service\Tmdb\TmdbItemMapper;
use App\Service\Tmdb\TmdbRateLimitedException;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps series we already hold up to date with their new episodes.
 *
 * The crawl adds titles and never revisits them — both crawl commands subtract
 * what is already stored, which is what makes them resumable. So a show that
 * was running when we crawled it is frozen at the episode count it had that
 * day, and season five of anything that started after our crawl will never
 * arrive on its own.
 *
 * What this deliberately does not do is rewrite the series itself. Running
 * CatalogWorkPersister::persist() over an existing row overwrites every scalar
 * on it — title, overview, artwork — so a nightly refresh built that way would
 * quietly undo every correction made in the admin. Only seasons and episodes
 * are touched here, through upsertSeason(), which is scoped to exactly that.
 *
 * Candidates come from TMDB's own changes feed rather than from walking our
 * catalog: 148k series is a week of API calls, and TMDB will tell us which
 * few hundred actually moved.
 *
 * Two calls per series that needs anything: one for the series to read its
 * season list and episode counts, then one per season whose count disagrees
 * with ours. A series that changed for some other reason — a new backdrop, a
 * translation — costs the first call and stops there.
 */
#[AsCommand(
    name: 'app:catalog:refresh-series',
    description: 'Pick up new seasons and episodes for series already in the catalog',
)]
final class CatalogRefreshSeriesCommand extends Command
{
    /** Clear the identity map this often; a season drags its episodes with it. */
    private const CLEAR_EVERY = 20;

    public function __construct(
        private readonly TmdbChanges $changes,
        private readonly TmdbClient $tmdb,
        private readonly TmdbItemMapper $mapper,
        private readonly TmdbMediaStore $media,
        private readonly CatalogWorkPersister $persister,
        private readonly WorkRepository $works,
        private readonly Connection $connection,
        private readonly ?DebugDataHolder $debugQueries = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of TMDB changes to read (max '.TmdbChanges::MAX_DAYS.')', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most series to look at this run', '500')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Series requests in flight at once', '8')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Refresh one series by TMDB id and exit, ignoring the changes feed')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Most season requests to spend this run', '600')
            ->addOption('media', null, InputOption::VALUE_REQUIRED, "Artwork to keep a copy of: none, posters, all", 'none')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->tmdb->isConfigured()) {
            $io->error('TMDB_API_KEY is not set.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $media = (string) $input->getOption('media');
        $concurrency = max(1, (int) $input->getOption('concurrency'));
        $limit = max(1, (int) $input->getOption('limit'));

        $candidates = $this->candidates($input, $io, $limit);
        if ([] === $candidates) {
            $io->success('Nothing to refresh.');

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $looked = 0;
        $seasonsWritten = 0;
        $episodesWritten = 0;
        $touched = 0;
        $failed = 0;

        /*
         * A ceiling on season requests, not on series.
         *
         * The original crawl stopped at eight seasons per show, so a soap that
         * has run for forty years reports forty missing seasons the first time
         * it is seen here — genuinely missing, but forty calls for one title.
         * Without a budget a nightly run's cost would depend on which shows
         * happened to change that day, which is no way to schedule anything.
         */
        $budget = max(1, (int) $input->getOption('budget'));

        foreach (array_chunk($candidates, $concurrency) as $window) {
            $requests = [];
            foreach ($window as $tmdbId) {
                $requests[$tmdbId] = ['path' => '/tv/'.$tmdbId];
            }

            try {
                $details = $this->tmdb->getMany($requests);
            } catch (TmdbRateLimitedException $e) {
                $io->warning('Backing off: '.$e->getMessage());
                break;
            }

            foreach ($details as $tmdbId => $detail) {
                $tmdbId = (int) $tmdbId;
                ++$looked;

                if (!\is_array($detail) || empty($detail['name'])) {
                    continue;
                }

                $wanted = $this->seasonsNeeding($tmdbId, $detail);
                if ([] === $wanted) {
                    continue;
                }

                // Never half a season: whatever is left of the budget is spent
                // on the first N of them and the rest wait for the next run.
                $wanted = \array_slice($wanted, 0, $budget, true);
                if ([] === $wanted) {
                    break 2;
                }

                if ($dryRun) {
                    ++$touched;
                    $io->writeln(sprintf(
                        '  ~ %s — seasons %s',
                        $detail['name'],
                        implode(', ', array_keys($wanted)),
                    ));
                    $seasonsWritten += \count($wanted);
                    $budget -= \count($wanted);
                    continue;
                }

                $budget -= \count($wanted);

                try {
                    $written = $this->writeSeasons($tmdbId, $wanted, $media, $io, $detail['name']);
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->writeln(sprintf('  ✗ %d: %s', $tmdbId, $e->getMessage()));
                    $this->persister->reset();
                    continue;
                }

                if ($written['seasons'] > 0) {
                    ++$touched;
                    $seasonsWritten += $written['seasons'];
                    $episodesWritten += $written['episodes'];
                }

                if (0 === $touched % self::CLEAR_EVERY) {
                    $this->persister->clear();
                }
            }

            $this->debugQueries?->reset();
        }

        if (!$dryRun) {
            $this->persister->flush();
            $this->persister->clear();
        }

        $io->success(sprintf(
            '%s of %s series %s, %s seasons and %s episodes%s, %s failed, in %.1fs.',
            number_format($touched),
            number_format($looked),
            $dryRun ? 'would change' : 'updated',
            number_format($seasonsWritten),
            number_format($episodesWritten),
            $dryRun ? ' would be written' : ' written',
            number_format($failed),
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function candidates(InputInterface $input, SymfonyStyle $io, int $limit): array
    {
        if (null !== ($one = $input->getOption('id'))) {
            $tmdbId = (int) $one;
            if (null === $this->works->findOneByTmdbId($tmdbId, 'series')) {
                $io->warning(sprintf('Series %d is not in the catalog — crawl it first.', $tmdbId));

                return [];
            }

            return [$tmdbId];
        }

        $ids = $this->changes->changedSeries(
            (int) $input->getOption('days'),
            static fn (string $message) => $io->writeln($message),
        );

        return \array_slice($ids, 0, $limit);
    }

    /**
     * Season numbers whose episode count does not match what we hold.
     *
     * TMDB's series payload lists every season with its episode_count, so this
     * costs nothing beyond the call we already made — no need to pull a season
     * down to find out it is unchanged.
     *
     * Season 0 is TMDB's bucket for specials. It is included: a special is an
     * episode and people do watch them.
     *
     * @param array<string, mixed> $detail
     *
     * @return array<int, int> season number => episodes TMDB says it has
     */
    private function seasonsNeeding(int $tmdbId, array $detail): array
    {
        $work = $this->works->findOneByTmdbId($tmdbId, 'series');
        if (null === $work || null === $work->getId()) {
            return [];
        }

        $held = $this->heldCounts((int) $work->getId());
        $wanted = [];

        foreach ($detail['seasons'] ?? [] as $season) {
            if (!\is_array($season) || !isset($season['season_number'])) {
                continue;
            }

            $number = (int) $season['season_number'];
            $theirs = (int) ($season['episode_count'] ?? 0);
            if (0 === $theirs) {
                continue;
            }

            /*
             * Only ever more, never fewer. TMDB sometimes lists a season before
             * its episodes exist, and a count that dropped is far more likely to
             * be them tidying up than us holding episodes that were never real —
             * rewriting on a shrink would delete episodes people have marked as
             * seen.
             */
            if ($theirs > ($held[$number] ?? 0)) {
                $wanted[$number] = $theirs;
            }
        }

        return $wanted;
    }

    /**
     * @return array<int, int> season number => episodes we hold
     */
    private function heldCounts(int $workId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT s.number, COUNT(e.id) AS episodes
             FROM seasons s
             LEFT JOIN episodes e ON e.season_id = s.id
             WHERE s.work_id = :work
             GROUP BY s.number',
            ['work' => $workId],
        )->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['number']] = (int) $row['episodes'];
        }

        return $counts;
    }

    /**
     * @param array<int, int> $wanted
     *
     * @return array{seasons: int, episodes: int}
     */
    private function writeSeasons(int $tmdbId, array $wanted, string $media, SymfonyStyle $io, string $name): array
    {
        $seasons = 0;
        $episodes = 0;

        foreach (array_keys($wanted) as $number) {
            $payload = $this->tmdb->get(sprintf('/tv/%d/season/%d', $tmdbId, $number));
            if (!\is_array($payload) || !isset($payload['episodes'])) {
                continue;
            }

            // Re-read: clear() between windows detaches what we looked up above.
            $work = $this->works->findOneByTmdbId($tmdbId, 'series');
            if (null === $work) {
                continue;
            }

            $season = $this->mapper->mapSeason($payload);
            if ('none' !== $media) {
                $season = $this->media->localizeSeason($season);
            }

            $this->persister->upsertSeason($work, $season);
            $this->persister->flush();

            ++$seasons;
            $episodes += \count($season['episodes'] ?? []);
            $io->writeln(sprintf('  ✓ %s S%d (%d eps)', $name, $number, \count($season['episodes'] ?? [])));
        }

        return ['seasons' => $seasons, 'episodes' => $episodes];
    }
}
