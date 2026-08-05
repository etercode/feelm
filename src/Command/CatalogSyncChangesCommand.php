<?php

namespace App\Command;

use App\Entity\ExternalId;
use App\Search\SearchTermsIndex;
use App\Service\Catalog\CatalogWorkPersister;
use App\Service\Catalog\WorkDetailsWriter;
use App\Service\Tmdb\TmdbAuthException;
use App\Service\Tmdb\TmdbChanges;
use App\Service\Tmdb\TmdbClient;
use App\Service\Tmdb\TmdbItemMapper;
use App\Service\Tmdb\TmdbRateLimitedException;
use Doctrine\DBAL\Logging\DebugDataHolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bring our films in line with TMDB's, once a day.
 *
 * ---- what this is for -------------------------------------------------------
 *
 * The nightly run used to take 2,000 films a night off the back of the initial
 * crawl and call that "the daily crawler". It was not: it was the first crawl
 * still running in instalments, and it meant that a film we already held could
 * be corrected on TMDB — a fixed runtime, a new poster, a release date that
 * finally firmed up — and we would never learn. Nothing in the codebase asked
 * /movie/changes. Series had this (see CatalogRefreshSeriesCommand); films did
 * not.
 *
 * So this is the whole daily job for films, and it has one rule: what TMDB
 * added or edited, we store.
 *
 * ---- why the feed is not filtered -------------------------------------------
 *
 * TMDB registers a brand-new record as a change on the day it is created, so
 * one feed answers both halves — the edits to what we hold and the titles that
 * did not exist yesterday. Filtering it to what we already store, which is what
 * the series version does, would quietly drop every new release.
 *
 * ---- the one filter there is ------------------------------------------------
 *
 * A changed id we do not hold is only worth fetching if it is genuinely new.
 * TMDB's daily id export is the tell: an id already listed there is a title we
 * have simply not crawled yet — part of the initial crawl's remainder, not
 * news — and pulling those in would drag that backlog back into the nightly
 * through the side door, which is exactly what this command exists to stop.
 * `--include-backlog` is there for when the backlog is finished and the
 * distinction stops mattering.
 *
 * ---- running this in dev ----------------------------------------------------
 *
 * Use APP_DEBUG=0. A few thousand titles asked for with append_to_response is a
 * couple of hundred kilobytes each, and the profiler keeps every response body:
 * the run dies inside the http-client at around 800 titles on 256M, and still
 * dies at 4,600 on 1G, because the growth is linear and no ceiling fixes it.
 * Resetting the traced client per window does not help either — what is
 * injected is a decorator around it. With the profiler off the same run holds
 * flat and finishes. Nothing to do for production, which runs with debug off.
 */
#[AsCommand(
    name: 'app:catalog:sync-changes',
    description: 'Store every film TMDB added or edited recently',
)]
final class CatalogSyncChangesCommand extends Command
{
    /** Everything the detail page and the facets are built from. */
    private const APPEND = 'credits,videos,release_dates,watch/providers,keywords,similar,recommendations';

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly TmdbChanges $changes,
        private readonly TmdbItemMapper $mapper,
        private readonly CatalogWorkPersister $persister,
        private readonly WorkDetailsWriter $details,
        private readonly SearchTermsIndex $searchTerms,
        private readonly \App\Service\Tmdb\TmdbIdExport $export,
        private readonly \Doctrine\DBAL\Connection $connection,
        /** The profiler's query log, null outside dev. See the note on memory above. */
        private readonly ?DebugDataHolder $debugQueries = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('refresh-export', null, InputOption::VALUE_NONE, "Re-download TMDB's daily movie id export first")
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of changes to read (max '.TmdbChanges::MAX_DAYS.')', '1')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'How many detail requests in flight at once', '20')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many titles. A guard against a freak day, not a queue.', '20000')
            ->addOption('include-backlog', null, InputOption::VALUE_NONE, 'Also fetch changed titles that are in the id export but uncrawled')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be fetched and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $days = (int) $input->getOption('days');
        $concurrency = max(1, (int) $input->getOption('concurrency'));
        $limit = max(1, (int) $input->getOption('limit'));

        /*
         * The export is not this command's data — it belongs to the backlog
         * crawl, and to refresh-popularity, which rescores the catalogue from
         * it without spending an API call. But this is the only movie job left
         * in the nightly run, so refreshing it moved here with the rest.
         * Without it popularity silently freezes at whatever it was on the day
         * the backlog crawl last ran, and the filter below loses its way of
         * telling a new record from an uncrawled one.
         */
        if ($input->getOption('refresh-export')) {
            $this->export->refresh(static fn (string $m) => $io->writeln($m));
        }

        $changed = $this->changes->changedMovies($days, static fn (string $m) => $io->writeln($m));
        if ([] === $changed) {
            $io->success('TMDB reports nothing changed.');

            return Command::SUCCESS;
        }

        $ours = $this->changes->weStore(ExternalId::SOURCE_TMDB, $changed);
        $mine = array_flip($ours);
        $strangers = array_values(array_filter($changed, static fn (int $id) => !isset($mine[$id])));

        $fresh = $input->getOption('include-backlog')
            ? $strangers
            : $this->notInExport($strangers);

        $io->writeln(sprintf(
            '  %s changed · %s ours · %s new · %s left to the backlog crawl',
            number_format(\count($changed)),
            number_format(\count($ours)),
            number_format(\count($fresh)),
            number_format(\count($strangers) - \count($fresh)),
        ));

        $ids = \array_slice(array_merge($ours, $fresh), 0, $limit);

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('%s titles would be fetched.', number_format(\count($ids))));

            return Command::SUCCESS;
        }

        $stored = 0;
        $gone = 0;
        $failed = 0;

        // Fetched in windows, stored one at a time — the same shape as the
        // crawl commands, for the same reasons.
        foreach (array_chunk($ids, $concurrency) as $window) {
            $requests = [];
            foreach ($window as $tmdbId) {
                $requests[$tmdbId] = ['path' => '/movie/'.$tmdbId, 'query' => ['append_to_response' => self::APPEND]];
            }

            try {
                $details = $this->tmdb->getMany($requests);
            } catch (TmdbAuthException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            } catch (TmdbRateLimitedException $e) {
                // Stop cleanly. Tomorrow's window overlaps today's, and --days
                // can be widened to cover a night that was cut short.
                $io->warning('Backing off: '.$e->getMessage());
                break;
            }

            foreach ($details as $tmdbId => $detail) {
                if (null === $detail || empty($detail['title'])) {
                    // Changed because it was deleted or hidden.
                    ++$gone;
                    continue;
                }

                $slugs = [];

                /*
                 * persist() is an upsert keyed on the external id, so the same
                 * call creates a new film and rewrites an existing one. A
                 * failure closes Doctrine's entity manager, so it is reset
                 * rather than allowed to fail every title after it.
                 */
                try {
                    $row = $this->mapper->mapMovie($detail, $slugs);
                    $work = $this->persister->persist($row);
                    $this->persister->flush();

                    /*
                     * Tags and similars are not columns on the works row, so
                     * persist() cannot store them and this has to say so
                     * separately. Without it the sync wrote a fresh overview
                     * and left the keywords, countries and studios at whatever
                     * they were on the day the title was first crawled —
                     * permanently, because the backfill that does write them
                     * only ever looks at rows it has not already stamped.
                     *
                     * The payload is the same one persist() just used; these
                     * fields were already in it and were being dropped.
                     */
                    $id = $work->getId();
                    if (null !== $id) {
                        $this->details->tags($id, $row['countries'] ?? [], $row['extras'] ?? []);
                        $this->details->related($id, $row['extras'] ?? []);
                        $this->details->stamp($id);
                    }

                    ++$stored;
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->writeln(sprintf('  ✗ %d: %s', $tmdbId, $e->getMessage()));
                    $this->persister->reset();
                    continue;
                }

                if (0 === $stored % 200) {
                    $this->persister->clear();
                    $io->writeln(sprintf('  %s stored…', number_format($stored)));
                }
            }

            // The profiler's query log grows with every window and nothing is
            // ever going to read it for a console command.
            $this->debugQueries?->reset();
        }

        $this->persister->flush();
        $this->persister->clear();

        if ($stored > 0) {
            $this->searchTerms->rebuild();
        }

        $io->success(sprintf(
            '%s stored, %s gone from TMDB, %s failed, in %.1fs.',
            number_format($stored),
            number_format($gone),
            number_format($failed),
            microtime(true) - $started,
        ));

        return $failed > 0 && 0 === $stored ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The changed ids that the daily export has never listed — which is what a
     * record created since the export was generated looks like.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function notInExport(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $fresh = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $known = $this->connection->executeQuery(
                'SELECT tmdb_id FROM tmdb_movie_ids WHERE tmdb_id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            )->fetchFirstColumn();

            $seen = array_flip(array_map('intval', $known));
            foreach ($chunk as $id) {
                if (!isset($seen[$id])) {
                    $fresh[] = $id;
                }
            }
        }

        return $fresh;
    }
}
