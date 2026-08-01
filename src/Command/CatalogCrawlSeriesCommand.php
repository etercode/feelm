<?php

namespace App\Command;

use App\Repository\WorkRepository;
use App\Search\SearchTermsIndex;
use App\Service\Catalog\CatalogWorkPersister;
use App\Service\Catalog\SeriesQualityGate;
use App\Service\Tmdb\TmdbAuthException;
use App\Service\Tmdb\TmdbClient;
use App\Service\Tmdb\TmdbItemMapper;
use App\Service\Tmdb\TmdbMediaStore;
use App\Service\Tmdb\TmdbRateLimitedException;
use App\Service\Tmdb\TmdbSeriesExport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crawls every series TMDB has, working from their daily id export.
 *
 * The movie counterpart is `app:catalog:crawl-all`, and this follows it closely
 * — the complete id list minus what is already stored, most popular first, safe
 * to stop and restart. Two things are different.
 *
 * Episodes come back in the same request. `append_to_response=season/1,season/2…`
 * returns whole seasons inline, so a series and its episodes cost one API call
 * rather than one per season. TMDB caps appends at twenty keys and four are
 * already spent on credits, videos, ratings and external ids, which is where the
 * sixteen-season ceiling comes from; seasons past it are simply not asked for,
 * and TMDB quietly omits any that do not exist.
 *
 * And what comes back is filtered. Anyone can add a series to TMDB, so the tail
 * of the export is largely stubs and home video — see {@see SeriesQualityGate}
 * for what is rejected and the measurements behind it. A rejected id is marked
 * done with its reason, so it is never fetched twice and the decision can be
 * reviewed, or reversed with --requeue.
 */
#[AsCommand(
    name: 'app:catalog:crawl-series',
    description: 'Crawl every TMDB series via the daily id export (resumable, popular first)',
)]
final class CatalogCrawlSeriesCommand extends Command
{
    /** TMDB allows 20 append_to_response keys; four are spent on the rest. */
    private const MAX_APPENDED_SEASONS = 16;

    /** Titles between flushes of Doctrine's identity map. */
    private const CLEAR_EVERY = 25;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly TmdbSeriesExport $export,
        private readonly TmdbItemMapper $mapper,
        private readonly TmdbMediaStore $media,
        private readonly CatalogWorkPersister $persister,
        private readonly SeriesQualityGate $gate,
        private readonly SearchTermsIndex $searchTerms,
        private readonly WorkRepository $works,
        /**
         * Only bound under APP_ENV=dev, where Doctrine keeps every query it has
         * run along with a backtrace so the profiler can show them.
         *
         * That is a few hundred rows for a web request and fatal here: a series
         * with eight seasons is well over a hundred inserts, so a run of a
         * hundred titles exhausted 256 MB before it finished. The crawl empties
         * the collection as it goes — nothing is watching the profiler during a
         * twelve-hour crawl anyway. Null in prod, where nothing collects.
         */
        private readonly ?DebugDataHolder $debugQueries = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many series to fetch this run', '500')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Requests in flight at once', '8')
            ->addOption('seasons', null, InputOption::VALUE_REQUIRED, 'Seasons of episodes to pull in the same request, 0-16. Costs no extra API calls, only a bigger response', '8')
            ->addOption('quality', null, InputOption::VALUE_REQUIRED, 'Junk filter: off, basic or strict', SeriesQualityGate::BASIC)
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Skip series that had finished before this year. A show that began in 1989 and ran on into the 2000s is kept')
            ->addOption('min-popularity', null, InputOption::VALUE_REQUIRED, 'Never fetch export rows below this popularity. The only setting that saves API calls rather than rows', '0')
            ->addOption('media', null, InputOption::VALUE_REQUIRED, 'Artwork to keep a copy of: none, posters, all', 'none')
            ->addOption('refresh-export', null, InputOption::VALUE_NONE, 'Re-download the id export even if it is fresh')
            ->addOption('requeue', null, InputOption::VALUE_REQUIRED, 'Put ids rejected for this reason back in the queue, then exit')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and report what the filter would do, without storing anything')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Report progress and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $minPopularity = max(0.0, (float) $input->getOption('min-popularity'));

        if ($input->getOption('status')) {
            return $this->status($io, $minPopularity);
        }

        if (\is_string($reason = $input->getOption('requeue'))) {
            $n = $this->export->requeue($reason);
            $io->success(sprintf('%s ids rejected as "%s" are back in the queue.', number_format($n), $reason));

            return Command::SUCCESS;
        }

        if (!$this->tmdb->isConfigured()) {
            $io->error('Set TMDB_API_READ_ACCESS_TOKEN or TMDB_API_KEY.');

            return Command::FAILURE;
        }

        $quality = (string) $input->getOption('quality');
        if (!\in_array($quality, SeriesQualityGate::LEVELS, true)) {
            $io->error('--quality must be off, basic or strict.');

            return Command::FAILURE;
        }

        $media = (string) $input->getOption('media');
        if (!\in_array($media, ['none', 'posters', 'all'], true)) {
            $io->error('--media must be none, posters or all.');

            return Command::FAILURE;
        }

        $since = null !== $input->getOption('since') ? (int) $input->getOption('since') : null;
        $seasons = max(0, min(self::MAX_APPENDED_SEASONS, (int) $input->getOption('seasons')));
        $limit = max(1, (int) $input->getOption('limit'));
        $concurrency = max(1, min(20, (int) $input->getOption('concurrency')));
        $dryRun = (bool) $input->getOption('dry-run');

        // The export is a day's snapshot; refreshing more than daily is pointless.
        $exportedOn = $this->export->exportedOn();
        if ($input->getOption('refresh-export') || null === $exportedOn || $exportedOn < (new \DateTimeImmutable('-1 day'))->format('Y-m-d')) {
            $this->export->refresh(static fn (string $message) => $io->writeln($message));
        }

        $ids = $this->export->nextIds($limit, $minPopularity);
        if ([] === $ids) {
            $io->success('Every exported series is already in the catalog or has been filtered out.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Crawling %s series — quality=%s seasons=%d concurrency=%d%s%s',
            number_format(\count($ids)),
            $quality,
            $seasons,
            $concurrency,
            null !== $since ? ' since='.$since : '',
            $dryRun ? ' [DRY RUN — nothing will be stored]' : '',
        ));

        $started = microtime(true);
        $stored = 0;
        $gone = 0;
        $failed = 0;
        $episodes = 0;
        $partial = 0;
        /** @var array<string, int> $rejected reason => count */
        $rejected = [];
        /** @var array<int, string|null> $attempted tmdb id => rejection reason */
        $attempted = [];

        foreach (array_chunk($ids, $concurrency) as $window) {
            $requests = [];
            foreach ($window as $tmdbId) {
                $requests[$tmdbId] = [
                    'path' => '/tv/'.$tmdbId,
                    'query' => ['append_to_response' => $this->appendKeys($seasons)],
                ];
            }

            try {
                $details = $this->tmdb->getMany($requests);
            } catch (TmdbAuthException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            } catch (TmdbRateLimitedException $e) {
                // Stop cleanly: the next run picks up exactly where this left off.
                $io->warning('Backing off: '.$e->getMessage());
                break;
            }

            foreach ($details as $tmdbId => $detail) {
                $tmdbId = (int) $tmdbId;

                if (null === $detail || empty($detail['name'])) {
                    // Deleted or hidden since the export was published.
                    ++$gone;
                    $attempted[$tmdbId] = 'gone';
                    continue;
                }

                $reason = $this->gate->reject($detail, $quality) ?? $this->tooOld($detail, $since);
                if (null !== $reason) {
                    $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
                    $attempted[$tmdbId] = $reason;
                    if ($dryRun && $output->isVerbose()) {
                        $io->writeln(sprintf('  ✗ %s — %s', $detail['name'], $reason));
                    }
                    continue;
                }

                if ($dryRun) {
                    ++$stored;
                    if ($output->isVerbose()) {
                        $io->writeln(sprintf('  ✓ %s (%s)', $detail['name'], substr((string) ($detail['first_air_date'] ?? ''), 0, 4)));
                    }
                    continue;
                }

                $slugs = [];
                $mapped = $this->mapper->mapSeriesShell($detail, $slugs);
                $row = $mapped['item'];
                $row['details']['seasons'] = $this->mapSeasons($detail, $mapped['seasonNumbers'], $media);
                $episodes += array_sum(array_map(
                    static fn (array $season) => \count($season['episodes'] ?? []),
                    $row['details']['seasons'],
                ));
                // Declared more seasons than --seasons asked for. The title is
                // still worth storing; the run says how many ended up short.
                if (\count($mapped['seasonNumbers']) > \count($row['details']['seasons'])) {
                    ++$partial;
                }

                if ('none' !== $media) {
                    $row = $this->media->localizeItem($row, $media);
                }

                /*
                 * A single unusable title must not end the run. Doctrine closes
                 * the entity manager when a flush fails, so the manager is reset
                 * before carrying on — otherwise every later title would fail too.
                 */
                try {
                    $this->persister->persist($row);
                    $this->persister->flush();
                    ++$stored;
                    $attempted[$tmdbId] = null;
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->writeln(sprintf('  ✗ %d: %s', $tmdbId, $e->getMessage()));
                    $this->persister->reset();
                    // Not marked done: a failure that was our fault deserves
                    // another go on the next run.
                    continue;
                }

                // Every 25 rather than the movie crawl's 100: a series drags a
                // hundred-odd episode rows behind it, so the identity map fills
                // four times faster per title.
                if (0 === $stored % self::CLEAR_EVERY) {
                    $this->persister->clear();
                    $io->writeln(sprintf('  %s stored…', number_format($stored)));
                }
            }

            // Per window, so an interrupted run does not re-fetch what it just did.
            if (!$dryRun) {
                $this->export->markCrawled($attempted);
                $attempted = [];
            }

            $this->debugQueries?->reset();
        }

        if (!$dryRun) {
            $this->persister->flush();
            $this->persister->clear();
            $this->export->markCrawled($attempted);

            if ($stored > 0) {
                $this->searchTerms->rebuild();
            }
        }

        $this->report($io, $started, $stored, $episodes, $partial, $seasons, $gone, $failed, $rejected, $minPopularity, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Had this series finished before the year being crawled from?
     *
     * Measured on when it went off air, not when it started. Two thirds of the
     * pre-1990 shows in a sample were still running after 1990 — The Simpsons
     * began in 1989, Coronation Street in 1960 — so cutting on the first air
     * date would throw away exactly the old titles worth having.
     *
     * A show still on air, or not yet started, has no last air date and stays.
     *
     * @param array<string, mixed> $detail
     */
    private function tooOld(array $detail, ?int $since): ?string
    {
        if (null === $since) {
            return null;
        }

        $ended = trim((string) ($detail['last_air_date'] ?? ''));
        if ('' === $ended) {
            // Nothing has aired yet, or nothing has stopped airing. Fall back to
            // when it began, which the quality gate has already required.
            $ended = trim((string) ($detail['first_air_date'] ?? ''));
        }

        // The reason code stays constant whatever year is passed, so a queue
        // crawled at one cutoff can still be requeued by name at another.
        return '' !== $ended && (int) substr($ended, 0, 4) < $since ? 'too_old' : null;
    }

    /**
     * One request's worth of appended sub-resources.
     *
     * Seasons are asked for by number without knowing how many exist — TMDB
     * returns the ones that do and says nothing about the rest, which is
     * cheaper than a request to find out first.
     */
    private function appendKeys(int $seasons): string
    {
        $keys = ['credits', 'videos', 'content_ratings', 'external_ids'];
        for ($n = 1; $n <= $seasons; ++$n) {
            $keys[] = 'season/'.$n;
        }

        return implode(',', $keys);
    }

    /**
     * The seasons that came back inline, in the mapper's shape.
     *
     * @param array<string, mixed> $detail
     * @param list<int>            $seasonNumbers
     *
     * @return list<array<string, mixed>>
     */
    private function mapSeasons(array $detail, array $seasonNumbers, string $media): array
    {
        $seasons = [];

        foreach ($seasonNumbers as $number) {
            $payload = $detail['season/'.$number] ?? null;
            if (!\is_array($payload) || !isset($payload['episodes'])) {
                // Past the append ceiling, or a season TMDB lists but cannot
                // serve. The series is still worth storing without it.
                continue;
            }

            $season = $this->mapper->mapSeason($payload);
            $seasons[] = 'none' !== $media ? $this->media->localizeSeason($season) : $season;
        }

        return $seasons;
    }

    /**
     * @param array<string, int> $rejected
     */
    private function report(
        SymfonyStyle $io,
        float $started,
        int $stored,
        int $episodes,
        int $partial,
        int $seasons,
        int $gone,
        int $failed,
        array $rejected,
        float $minPopularity,
        bool $dryRun,
    ): void {
        $elapsed = microtime(true) - $started;
        $cut = array_sum($rejected);
        $seen = $stored + $cut + $gone;

        $io->success(sprintf(
            '%s %s, %s episodes, %s filtered out, %s gone from TMDB, %s failed, %.1f series/sec.',
            number_format($stored),
            $dryRun ? 'would be stored' : 'stored',
            number_format($episodes),
            number_format($cut),
            number_format($gone),
            number_format($failed),
            $elapsed > 0 ? $seen / $elapsed : 0,
        ));

        if ($partial > 0) {
            $io->writeln(sprintf(
                '%s of them run past season %d and were stored without the rest — TMDB allows only so many appended to one request.',
                number_format($partial),
                $seasons,
            ));
        }

        if ([] !== $rejected) {
            arsort($rejected);
            $io->writeln('Filtered out:');
            foreach ($rejected as $reason => $count) {
                $io->writeln(sprintf('  %-16s %6s  (%.0f%% of what was fetched)', $reason, number_format($count), $seen > 0 ? 100 * $count / $seen : 0));
            }
            $io->writeln('  Reasons are kept on the queue row — app:catalog:crawl-series --requeue=<reason> undoes one.');
        }

        if ($dryRun) {
            return;
        }

        $remaining = $this->export->remaining($minPopularity);
        $io->writeln(sprintf(
            '%s series in the catalog, %s left in the queue.',
            number_format($this->works->countByType('series')),
            number_format($remaining),
        ));

        if ($remaining > 0 && $seen > 0 && $elapsed > 0) {
            $io->writeln(sprintf(
                'At this rate that is about %s more hours.',
                number_format($remaining * ($elapsed / $seen) / 3600, 1),
            ));
        }
    }

    private function status(SymfonyStyle $io, float $minPopularity): int
    {
        $total = $this->export->count();
        $remaining = $this->export->remaining($minPopularity);
        $skipped = $this->export->skipCounts();

        $rows = [
            ['Export date' => $this->export->exportedOn() ?? 'never downloaded'],
            ['Ids in export' => number_format($total)],
            ['Series in catalog' => number_format($this->works->countByType('series'))],
            ['Filtered out' => number_format(array_sum($skipped))],
            ['Still to fetch' => number_format($remaining)],
        ];

        foreach ($skipped as $reason => $count) {
            $rows[] = ['  · '.$reason => number_format($count)];
        }

        $io->definitionList(...$rows);

        return Command::SUCCESS;
    }
}
