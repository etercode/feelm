<?php

namespace App\Command;

use App\Repository\WorkRepository;
use App\Search\SearchTermsIndex;
use App\Service\Catalog\CatalogWorkPersister;
use App\Service\Tmdb\TmdbAuthException;
use App\Service\Tmdb\TmdbClient;
use App\Service\Tmdb\TmdbItemMapper;
use App\Service\Tmdb\TmdbMediaStore;
use App\Service\Tmdb\TmdbRateLimitedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:crawl',
    description: 'Crawl TMDB movies/series into the database (resumable, skips existing)',
)]
class CatalogCrawlCommand extends Command
{
    private const YEAR_START = 1960;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly TmdbItemMapper $mapper,
        private readonly TmdbMediaStore $media,
        private readonly CatalogWorkPersister $persister,
        private readonly SearchTermsIndex $searchTerms,
        private readonly WorkRepository $works,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max new detail/season API calls this run', '200')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Print crawl cursor and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $crawlDir = $this->projectDir.'/data/crawl';
        $statePath = $crawlDir.'/state.json';

        if (!is_dir($crawlDir)) {
            mkdir($crawlDir, 0775, true);
        }

        $state = $this->loadState($statePath);

        if ($input->getOption('status')) {
            $io->writeln(json_encode([
                'phase' => $state['phase'],
                'year' => $state['year'],
                'month' => $state['month'],
                'page' => $state['page'],
                'resultOffset' => $state['resultOffset'],
                'seasonQueue' => \count($state['seasonQueue']),
                'finished' => $state['finished'],
                'done' => $state['done'],
                'database' => [
                    'movies' => $this->works->countByType('movie'),
                    'series' => $this->works->countByType('series'),
                ],
                'lastRunAt' => $state['lastRunAt'],
                'lastError' => $state['lastError'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if (!$this->tmdb->isConfigured()) {
            $io->error('Set TMDB_API_READ_ACCESS_TOKEN or TMDB_API_KEY in .env.dev / .env.local');

            return Command::FAILURE;
        }

        if ($state['finished']) {
            $io->success('Crawl already finished. Delete data/crawl/state.json to restart the cursor.');

            return Command::SUCCESS;
        }

        $budget = max(1, (int) $input->getOption('limit'));
        $state['lastError'] = null;
        $titlesThisRun = 0;
        $skipped = 0;

        $io->writeln(sprintf(
            'TMDB crawl → DB — phase=%s year=%d%s page=%d queue=%d budget=%d',
            $state['phase'],
            $state['year'],
            null !== $state['month'] ? ' month='.$state['month'] : '',
            $state['page'],
            \count($state['seasonQueue']),
            $budget,
        ));

        try {
            while ($budget > 0 && $state['seasonQueue']) {
                $job = array_shift($state['seasonQueue']);
                $tmdbId = (int) $job['tmdbId'];
                $seasonNo = (int) $job['season'];

                $series = $this->works->findOneByTmdbId($tmdbId, 'series');
                if (null === $series) {
                    $io->writeln(sprintf('  ✗ series %d: missing in DB, drop S%d', $tmdbId, $seasonNo));
                    continue;
                }
                if ($this->seriesHasSeason($series->getId(), $seasonNo)) {
                    ++$skipped;
                    $io->writeln(sprintf('  · skip series %d S%d (already in db)', $tmdbId, $seasonNo));
                    continue;
                }

                $data = $this->tmdb->get('/tv/'.$tmdbId.'/season/'.$seasonNo);
                --$budget;
                if (null === $data) {
                    $io->writeln(sprintf('  ✗ series %d S%d: not found', $tmdbId, $seasonNo));
                    continue;
                }

                // Re-load after possible clears.
                $series = $this->works->findOneByTmdbId($tmdbId, 'series');
                if (null === $series) {
                    continue;
                }
                $season = $this->media->localizeSeason($this->mapper->mapSeason($data));
                $this->persister->upsertSeason($series, $season);
                $this->persister->flush();
                ++$state['done']['seasons'];
                $io->writeln(sprintf(
                    '  ✓ series %d S%d (%d eps)',
                    $tmdbId,
                    $seasonNo,
                    \count($data['episodes'] ?? []),
                ));
            }

            while ($budget > 0 && !$state['finished']) {
                $page = $this->discoverPage($state);
                $results = $page['results'] ?? [];
                if (!\is_array($results)) {
                    $results = [];
                }
                $totalPages = min((int) ($page['total_pages'] ?? 1), 500);

                if (!$results) {
                    if (!$this->advanceCursor($state, $totalPages, $io)) {
                        break;
                    }
                    continue;
                }

                $offset = (int) $state['resultOffset'];
                for ($i = $offset; $i < \count($results); ++$i) {
                    if ($budget <= 0) {
                        break;
                    }
                    $row = $results[$i];
                    $state['resultOffset'] = $i + 1;
                    if (!\is_array($row) || !isset($row['id'])) {
                        continue;
                    }
                    $tmdbId = (int) $row['id'];

                    if ('movies' === $state['phase']) {
                        if (null !== $this->works->findOneByTmdbId($tmdbId, 'movie')) {
                            ++$skipped;
                            $io->writeln(sprintf('  · skip movie %d (already in db)', $tmdbId));
                            continue;
                        }

                        $detail = $this->tmdb->get('/movie/'.$tmdbId, [
                            'append_to_response' => 'credits,videos,release_dates',
                        ]);
                        --$budget;
                        if (null === $detail || empty($detail['title'])) {
                            $io->writeln(sprintf('  ✗ movie %d', $tmdbId));
                            continue;
                        }

                        $mapped = $this->media->localizeItem(
                            $this->mapper->mapMovie($detail, $state['slugs']),
                        );
                        $this->persister->persist($mapped);
                        $this->persister->flush();
                        ++$state['done']['movies'];
                        ++$titlesThisRun;
                        $io->writeln(sprintf('  ✓ movie %s (%s)', $mapped['title'], $mapped['year'] ?? '?'));
                    } else {
                        if (null !== $this->works->findOneByTmdbId($tmdbId, 'series')) {
                            ++$skipped;
                            $io->writeln(sprintf('  · skip series %d (already in db)', $tmdbId));
                            continue;
                        }

                        $detail = $this->tmdb->get('/tv/'.$tmdbId, [
                            'append_to_response' => 'credits,videos,content_ratings,external_ids',
                        ]);
                        --$budget;
                        if (null === $detail || empty($detail['name'])) {
                            $io->writeln(sprintf('  ✗ series %d', $tmdbId));
                            continue;
                        }

                        $mapped = $this->mapper->mapSeriesShell($detail, $state['slugs']);
                        $mapped['item'] = $this->media->localizeItem($mapped['item']);
                        $this->persister->persist($mapped['item']);
                        $this->persister->flush();

                        foreach ($mapped['seasonNumbers'] as $n) {
                            if (!$this->queueHas($state['seasonQueue'], $tmdbId, $n)) {
                                $state['seasonQueue'][] = ['tmdbId' => $tmdbId, 'season' => $n];
                            }
                        }

                        ++$state['done']['series'];
                        ++$titlesThisRun;
                        $io->writeln(sprintf(
                            '  ✓ series %s (%s) — queued %d seasons',
                            $mapped['item']['title'],
                            $mapped['item']['year'] ?? '?',
                            \count($mapped['seasonNumbers']),
                        ));
                    }

                    if (0 === ($titlesThisRun % 25)) {
                        $this->persister->clear();
                    }
                }

                if ($budget <= 0) {
                    break;
                }
                if (!$this->advanceCursor($state, $totalPages, $io)) {
                    break;
                }
            }
        } catch (TmdbAuthException $e) {
            $state['lastError'] = $e->getMessage();
            $this->saveState($statePath, $state);
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (TmdbRateLimitedException $e) {
            $state['lastError'] = $e->getMessage();
            $io->warning('Stopping early: '.$e->getMessage());
        } catch (\Throwable $e) {
            $state['lastError'] = $e->getMessage();
            $io->error($e->getMessage());
        }

        $this->saveState($statePath, $state);

        // New titles mean new words to suggest corrections from.
        if ($titlesThisRun > 0) {
            $this->searchTerms->rebuild();
        }

        $io->writeln(sprintf(
            'Batch done. new=%d skipped=%d seasons=%d queue=%d db movies=%d series=%d next=%s %d%s p%d%s',
            $titlesThisRun,
            $skipped,
            $state['done']['seasons'],
            \count($state['seasonQueue']),
            $this->works->countByType('movie'),
            $this->works->countByType('series'),
            $state['phase'],
            $state['year'],
            null !== $state['month'] ? '-'.$state['month'] : '',
            $state['page'],
            $state['finished'] ? ' [FINISHED]' : '',
        ));

        return Command::SUCCESS;
    }

    private function seriesHasSeason(?int $itemId, int $seasonNo): bool
    {
        if (null === $itemId) {
            return false;
        }

        $found = $this->works->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM seasons WHERE work_id = :work AND number = :n LIMIT 1',
            ['work' => $itemId, 'n' => $seasonNo],
        );

        return false !== $found && null !== $found;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function discoverPage(array $state): array
    {
        $isMovies = 'movies' === $state['phase'];
        $path = $isMovies ? '/discover/movie' : '/discover/tv';
        $query = [
            'page' => $state['page'],
            'include_adult' => 'false',
            'sort_by' => $isMovies ? 'primary_release_date.asc' : 'first_air_date.asc',
        ];

        if (null !== $state['month']) {
            $m = sprintf('%02d', $state['month']);
            $last = sprintf('%02d', (int) (new \DateTimeImmutable(sprintf('%d-%02d-01', $state['year'], $state['month'])))->format('t'));
            $gte = sprintf('%d-%s-01', $state['year'], $m);
            $lte = sprintf('%d-%s-%s', $state['year'], $m, $last);
            if ($isMovies) {
                $query['primary_release_date.gte'] = $gte;
                $query['primary_release_date.lte'] = $lte;
            } else {
                $query['first_air_date.gte'] = $gte;
                $query['first_air_date.lte'] = $lte;
            }
        } elseif ($isMovies) {
            $query['primary_release_year'] = $state['year'];
        } else {
            $query['first_air_date_year'] = $state['year'];
        }

        $page = $this->tmdb->get($path, $query);
        if (null === $page) {
            throw new TmdbRateLimitedException('discover returned empty');
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function advanceCursor(array &$state, int $totalPages, SymfonyStyle $io): bool
    {
        $hitCap = $totalPages >= 500;
        $state['resultOffset'] = 0;

        if (null === $state['month'] && $hitCap && $state['page'] >= 500) {
            $state['month'] = 1;
            $state['page'] = 1;
            $io->writeln(sprintf('  ↪ %d hit page cap — switching to month windows', $state['year']));

            return true;
        }

        if ($state['page'] < $totalPages) {
            ++$state['page'];

            return true;
        }

        if (null !== $state['month']) {
            if ($state['month'] < 12) {
                ++$state['month'];
                $state['page'] = 1;

                return true;
            }
            $state['month'] = null;
        }

        $yearEnd = (int) (new \DateTimeImmutable())->format('Y') + 1;
        if ($state['year'] < $yearEnd) {
            ++$state['year'];
            $state['page'] = 1;
            $state['month'] = null;

            return true;
        }

        if ('movies' === $state['phase']) {
            $io->writeln('✓ Movies phase complete — starting series');
            $state['phase'] = 'series';
            $state['year'] = self::YEAR_START;
            $state['month'] = null;
            $state['page'] = 1;

            return true;
        }

        $state['finished'] = true;

        return false;
    }

    /**
     * @param list<array{tmdbId: int, season: int}> $queue
     */
    private function queueHas(array $queue, int $tmdbId, int $season): bool
    {
        foreach ($queue as $job) {
            if ((int) $job['tmdbId'] === $tmdbId && (int) $job['season'] === $season) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(string $path): array
    {
        $default = [
            'phase' => 'movies',
            'year' => self::YEAR_START,
            'month' => null,
            'page' => 1,
            'resultOffset' => 0,
            'seasonQueue' => [],
            'slugs' => [],
            'done' => ['movies' => 0, 'series' => 0, 'seasons' => 0],
            'finished' => false,
            'lastRunAt' => null,
            'lastError' => null,
        ];
        if (!is_readable($path)) {
            return $default;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }
        if (!\is_array($decoded)) {
            return $default;
        }

        $state = array_replace_recursive($default, $decoded);

        // Skip years before the catalog floor (e.g. old state still on 1890s).
        if ((int) ($state['year'] ?? 0) < self::YEAR_START && empty($state['finished'])) {
            $state['year'] = self::YEAR_START;
            $state['month'] = null;
            $state['page'] = 1;
            $state['resultOffset'] = 0;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(string $statePath, array &$state): void
    {
        $state['lastRunAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
