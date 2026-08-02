<?php

namespace App\Command;

use App\Entity\ExternalId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-reads popularity from TMDB's daily export for titles we already hold.
 *
 * Popularity was written once, when a title was first crawled, and never again:
 * CatalogWorkPersister sets it, and the persister only ever runs on a title the
 * crawl has not seen before. So every number in the catalog was frozen on the
 * day it arrived, which matters because popularity is what orders the rails,
 * the front page and the release queue — the whole site is sorted by a
 * measurement that stopped measuring.
 *
 * The fix costs no API calls. TMDB's id export carries popularity alongside
 * each id, and the crawl queue tables already store it, so this is a join
 * between two tables we refresh anyway. Run it after the export is re-read and
 * the whole catalog is current.
 *
 * Rows are only written where the number actually differs. Most of the catalog
 * is obscure enough that TMDB's figure does not move day to day, and rewriting
 * 876k unchanged rows nightly is bloat for nothing.
 */
#[AsCommand(
    name: 'app:catalog:refresh-popularity',
    description: "Copy popularity from TMDB's daily export onto works we already hold",
)]
final class CatalogRefreshPopularityCommand extends Command
{
    /**
     * Queue table and the external-id source that points into it.
     *
     * @var array<string, array{table: string, source: string}>
     */
    private const SOURCES = [
        'movie' => ['table' => 'tmdb_movie_ids', 'source' => ExternalId::SOURCE_TMDB],
        'series' => ['table' => 'tmdb_series_ids', 'source' => ExternalId::SOURCE_TMDB_TV],
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only movie or series')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per statement', '20000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would change and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $only = $input->getOption('type');
        $batch = max(1000, (int) $input->getOption('batch'));
        $dryRun = (bool) $input->getOption('dry-run');

        $total = 0;

        foreach (self::SOURCES as $type => $config) {
            if (null !== $only && $only !== $type) {
                continue;
            }

            $exported = $this->connection->fetchOne(
                sprintf('SELECT MAX(exported_on) FROM %s', $config['table']),
            );

            if (null === $exported) {
                $io->writeln(sprintf('%s: no export loaded yet, skipping.', $type));
                continue;
            }

            $changed = $dryRun
                ? $this->countStale($config)
                : $this->apply($config, $batch);

            $total += $changed;
            $io->writeln(sprintf(
                '%s: %s %s (export of %s).',
                $type,
                number_format($changed),
                $dryRun ? 'would change' : 'updated',
                $exported,
            ));
        }

        $io->success(sprintf(
            '%s titles %s in %.1fs.',
            number_format($total),
            $dryRun ? 'would be updated' : 'updated',
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array{table: string, source: string} $config
     */
    private function countStale(array $config): int
    {
        return (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*)
                 FROM works w
                 JOIN external_ids x ON x.work_id = w.id AND x.source = :source
                 JOIN %s q ON q.tmdb_id = x.external_id::bigint
                 WHERE w.popularity IS DISTINCT FROM q.popularity',
                $config['table'],
            ),
            ['source' => $config['source']],
        );
    }

    /**
     * Written in batches by work id.
     *
     * One statement over the whole catalog holds a lock and a transaction open
     * for the duration and leaves 876k dead tuples behind in the worst case;
     * batching keeps each one short enough that the nightly run cannot sit on
     * top of a page load.
     *
     * @param array{table: string, source: string} $config
     */
    private function apply(array $config, int $batch): int
    {
        $sql = sprintf(
            'WITH stale AS (
                SELECT w.id, q.popularity
                FROM works w
                JOIN external_ids x ON x.work_id = w.id AND x.source = :source
                JOIN %s q ON q.tmdb_id = x.external_id::bigint
                WHERE w.id > :after
                  AND w.popularity IS DISTINCT FROM q.popularity
                ORDER BY w.id
                LIMIT :batch
             ), bounds AS (
                SELECT MAX(id) AS last_id FROM stale
             )
             UPDATE works w
             SET popularity = s.popularity
             FROM stale s
             WHERE w.id = s.id
             RETURNING (SELECT last_id FROM bounds)',
            $config['table'],
        );

        $changed = 0;
        $after = 0;

        /*
         * Walking by id rather than by OFFSET. The rows this matches stop
         * matching the moment they are written, so an offset would step over
         * everything it just fixed.
         */
        while (true) {
            $rows = $this->connection->fetchAllAssociative($sql, [
                'source' => $config['source'],
                'after' => $after,
                'batch' => $batch,
            ], [
                'batch' => ParameterType::INTEGER,
                'after' => ParameterType::INTEGER,
            ]);

            if ([] === $rows) {
                break;
            }

            $changed += \count($rows);
            $last = (int) ($rows[0]['last_id'] ?? 0);
            if ($last <= $after) {
                break;
            }
            $after = $last;
        }

        return $changed;
    }
}
