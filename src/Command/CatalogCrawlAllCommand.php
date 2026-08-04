<?php

namespace App\Command;

use App\Repository\WorkRepository;
use App\Search\SearchTermsIndex;
use App\Service\Catalog\CatalogWorkPersister;
use App\Service\Tmdb\TmdbAuthException;
use App\Service\Tmdb\TmdbClient;
use App\Service\Tmdb\TmdbIdExport;
use App\Service\Tmdb\TmdbItemMapper;
use App\Service\Tmdb\TmdbMediaStore;
use App\Service\Tmdb\TmdbRateLimitedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crawls every movie TMDB has, working from their daily id export.
 *
 * The sibling command (`app:catalog:crawl`) walks /discover by release year,
 * which is fine for picking up what is new but cannot cover the whole catalog:
 * it caps at 500 pages per query, its ordering shifts under a long crawl, and
 * films without a release date never appear. This one takes the complete list
 * and subtracts what is already stored, so "what is left" is a query rather
 * than a cursor — safe to stop, restart, or run twice.
 *
 * Popular titles come first, so the catalog is useful long before it is
 * complete.
 */
#[AsCommand(
    name: 'app:catalog:crawl-all',
    description: 'Crawl every TMDB movie via the daily id export (resumable, popular first)',
)]
final class CatalogCrawlAllCommand extends Command
{
    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly TmdbIdExport $export,
        private readonly TmdbItemMapper $mapper,
        private readonly TmdbMediaStore $media,
        private readonly CatalogWorkPersister $persister,
        private readonly SearchTermsIndex $searchTerms,
        private readonly WorkRepository $works,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many titles to fetch this run', '500')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only crawl titles released in this year or later (needs app:catalog:queue)')
            ->addOption('min-popularity', null, InputOption::VALUE_REQUIRED, 'Skip titles below this TMDB popularity. 1 is roughly "somebody has heard of it"')
            ->addOption('refresh-export', null, InputOption::VALUE_NONE, 'Re-download the id export even if it is fresh')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Requests in flight at once. One at a time is latency-bound at ~4/s', '8')
            ->addOption('media', null, InputOption::VALUE_REQUIRED, "Artwork to keep a copy of: none, posters, all. Anything not copied still displays from TMDB's CDN", 'none')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Report progress and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->status($io, $this->since($input), $this->floor($input));
        }

        if (!$this->tmdb->isConfigured()) {
            $io->error('Set TMDB_API_READ_ACCESS_TOKEN or TMDB_API_KEY.');

            return Command::FAILURE;
        }

        // The export is a day's snapshot; refreshing more than daily is pointless.
        $exportedOn = $this->export->exportedOn();
        if ($input->getOption('refresh-export') || null === $exportedOn || $exportedOn < (new \DateTimeImmutable('-1 day'))->format('Y-m-d')) {
            $this->export->refresh(static fn (string $message) => $io->writeln($message));
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $concurrency = max(1, min(20, (int) $input->getOption('concurrency')));
        $media = (string) $input->getOption('media');
        if (!\in_array($media, ['none', 'posters', 'all'], true)) {
            $io->error('--media must be none, posters or all.');

            return Command::FAILURE;
        }
        $since = $this->since($input);
        $floor = $this->floor($input);
        $ids = $this->export->nextIds($limit, $since, $floor);

        if ([] === $ids) {
            $io->success(match (true) {
                null !== $floor => sprintf('Nothing left above popularity %s%s. Lower --min-popularity to go further down the tail.', $floor, null === $since ? '' : ' from '.$since.' onwards'),
                null === $since => 'Every exported movie is already in the catalog.',
                default => sprintf('Every queued title from %d onwards is already in the catalog. Run app:catalog:queue --since=%d if the queue is not filled yet.', $since, $since),
            });

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $stored = 0;
        $missing = 0;
        $failed = 0;

        /*
         * Fetched in windows, stored one at a time. The window is what makes
         * this fast; the storing stays serial because Doctrine is not worth
         * making concurrent for the sake of it.
         */
        foreach (array_chunk($ids, $concurrency) as $window) {
            $requests = [];
            foreach ($window as $tmdbId) {
                $requests[$tmdbId] = [
                    'path' => '/movie/'.$tmdbId,
                    'query' => ['append_to_response' => 'credits,videos,release_dates,watch/providers,keywords,similar,recommendations'],
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
                if (null === $detail || empty($detail['title'])) {
                    // Deleted or hidden since the export was published.
                    ++$missing;
                    $this->forget($tmdbId);
                    continue;
                }

                $slugs = [];
                $row = $this->mapper->mapMovie($detail, $slugs);
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
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->writeln(sprintf('  ✗ %d: %s', $tmdbId, $e->getMessage()));
                    $this->persister->reset();
                    continue;
                }

                if (0 === $stored % 100) {
                    $this->persister->clear();
                    $io->writeln(sprintf('  %s stored…', number_format($stored)));
                }
            }
        }

        $this->persister->flush();
        $this->persister->clear();

        // Everything attempted is off the queue, successes and failures alike;
        // a failure that was our fault is picked up by re-queuing, not by
        // hammering the same broken title for the rest of the run.
        $this->export->markCrawled($ids);

        if ($stored > 0) {
            $this->searchTerms->rebuild();
        }

        $elapsed = microtime(true) - $started;
        $remaining = $this->export->remaining($since, $floor);

        $io->success(sprintf(
            '%s stored, %s gone from TMDB, %s failed, %.1f titles/sec. %s left%s.',
            number_format($stored),
            number_format($missing),
            number_format($failed),
            $elapsed > 0 ? ($stored + $missing) / $elapsed : 0,
            number_format($remaining),
            // With a year filter, "left" counts the dated queue, not the export.
            null === $since
                ? ' of '.number_format($this->export->count())
                : sprintf(' from %d onwards (of what is queued so far)', $since),
        ));

        if ($remaining > 0) {
            $io->writeln(sprintf(
                'At this rate that is about %s more hours.',
                number_format($remaining * ($elapsed / max(1, $stored + $missing)) / 3600, 1),
            ));
        }

        return Command::SUCCESS;
    }

    private function since(InputInterface $input): ?int
    {
        return null !== $input->getOption('since') ? (int) $input->getOption('since') : null;
    }

    private function floor(InputInterface $input): ?float
    {
        return null !== $input->getOption('min-popularity')
            ? (float) $input->getOption('min-popularity')
            : null;
    }

    private function status(SymfonyStyle $io, ?int $since, ?float $floor = null): int
    {
        $total = $this->export->count();
        $remaining = $this->export->remaining($since, $floor);
        $inCatalog = $this->works->countByType('movie');

        $rows = [
            ['Export date' => $this->export->exportedOn() ?? 'never downloaded'],
            ['Ids in export' => number_format($total)],
            ['Movies in catalog' => number_format($inCatalog)],
        ];

        if (null !== $since) {
            $rows[] = ['Queued from '.$since => number_format($this->export->remaining($since) + $inCatalog)];
        }

        if (null !== $floor) {
            $rows[] = ['Popularity floor' => (string) $floor];
        }

        $rows[] = ['Still to fetch' => number_format($remaining)];
        $rows[] = ['Hours left at 15/sec' => number_format($remaining / 15 / 3600, 1)];

        $io->definitionList(...$rows);

        return Command::SUCCESS;
    }

    /** A title in the export that TMDB no longer serves; do not keep asking. */
    private function forget(int $tmdbId): void
    {
        $this->works->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM tmdb_movie_ids WHERE tmdb_id = :id',
            ['id' => $tmdbId],
        );
    }
}
