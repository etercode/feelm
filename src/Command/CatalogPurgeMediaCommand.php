<?php

namespace App\Command;

use App\Service\Media\ObjectStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes the artwork of hidden titles back out of the bucket.
 *
 * Everything else about hiding a title is reversible; this is not, and that is
 * why it is its own command rather than a step inside prune or the moderation
 * endpoint. Deleting the object frees the space, and the row keeps TMDB's
 * original URL — so a title brought back still shows a poster, served from
 * TMDB, and the next mirror run copies it again if it is wanted.
 *
 * That is the whole design: `poster` is TMDB's, `poster_mirror` is ours, and
 * only ours is destroyed. See the note on Work::$posterMirror.
 *
 * Runs against anything hidden, whichever way it got there — the prune rule,
 * an 18+ flag, a moderator hiding a duplicate. A title nobody can reach has no
 * claim on paid storage.
 */
#[AsCommand(
    name: 'app:catalog:purge-media',
    description: 'Delete mirrored artwork belonging to hidden titles',
)]
final class CatalogPurgeMediaCommand extends Command
{
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly ObjectStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete; without this it only counts')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many images');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->storage->isConfigured()) {
            $io->error('No object storage configured — nothing to purge from.');

            return Command::FAILURE;
        }

        $counts = $this->connection->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE poster_mirror IS NOT NULL) AS posters,
                    COUNT(*) FILTER (WHERE backdrop_mirror IS NOT NULL) AS backdrops
               FROM works WHERE deleted_at IS NOT NULL',
        ) ?: ['posters' => 0, 'backdrops' => 0];

        $total = (int) $counts['posters'] + (int) $counts['backdrops'];

        $io->title('Mirrored artwork on hidden titles');
        $io->listing([
            number_format((int) $counts['posters']).' posters',
            number_format((int) $counts['backdrops']).' backdrops',
        ]);

        if (0 === $total) {
            $io->success('Nothing to purge.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('apply')) {
            $io->warning(sprintf(
                '%s objects would be deleted. Dry run — pass --apply to commit.',
                number_format($total),
            ));

            return Command::SUCCESS;
        }

        $limit = $input->getOption('limit');
        $limit = null === $limit ? null : max(1, (int) $limit);

        $deleted = 0;
        $failed = 0;
        $started = microtime(true);

        while (null === $limit || $deleted + $failed < $limit) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, poster_mirror, backdrop_mirror
                   FROM works
                  WHERE deleted_at IS NOT NULL
                    AND (poster_mirror IS NOT NULL OR backdrop_mirror IS NOT NULL)
                  LIMIT '.self::BATCH,
            );

            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                foreach (['poster_mirror', 'backdrop_mirror'] as $column) {
                    $key = $row[$column] ?? null;
                    if (null === $key || '' === $key) {
                        continue;
                    }

                    try {
                        $this->storage->delete((string) $key);
                        ++$deleted;
                    } catch (\Throwable $e) {
                        // A key already gone is the same outcome we wanted, and
                        // the column is cleared either way — leaving it set
                        // would mean serving a URL to an object that is not
                        // there, which is worse than losing the record of it.
                        ++$failed;
                    }
                }

                /*
                 * Cleared after the deletes, per row. If the process dies
                 * mid-batch the rows it finished are done and the rest are
                 * still queued — the query above is what makes this resumable,
                 * so nothing may leave the queue before its objects are gone.
                 */
                $this->connection->executeStatement(
                    'UPDATE works SET poster_mirror = NULL, backdrop_mirror = NULL WHERE id = :id',
                    ['id' => $row['id']],
                );
            }

            $io->writeln(sprintf('  %s deleted…', number_format($deleted)));
        }

        $io->success(sprintf(
            '%s objects deleted (%s failed) in %.1fs. TMDB URLs are untouched, so a restored title still has a poster.',
            number_format($deleted),
            number_format($failed),
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }
}
