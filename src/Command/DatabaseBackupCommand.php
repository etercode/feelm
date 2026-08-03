<?php

namespace App\Command;

use App\Service\Media\ObjectStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends a database dump to the bucket and keeps the last few.
 *
 * The dump arrives on standard input rather than being taken here, because
 * `pg_dump` lives in the database container and this runs in the PHP one. The
 * wrapper in deploy/backup-db.sh pipes one into the other, which is also what
 * keeps the whole thing streaming: several gigabytes go from Postgres to
 * Singapore without ever being a string in PHP or, on a good day, a file on
 * disk.
 *
 * Offsite is the point. A backup on the same disk as the database survives a
 * dropped table and nothing else — not a failed disk, not a lost VPS, not the
 * provider having a bad day. The bucket being in Singapore, which is a nuisance
 * for serving images, is a virtue here.
 */
#[AsCommand(
    name: 'app:db:backup',
    description: 'Upload a database dump from stdin to object storage, pruning old ones',
)]
final class DatabaseBackupCommand extends Command
{
    private const PREFIX = 'backups/';

    /**
     * How many dumps to keep.
     *
     * Three is deliberate rather than arbitrary: enough that a corruption
     * noticed the morning after still has a clean copy behind it, few enough
     * that a multi-gigabyte dump does not quietly become the largest thing in
     * the bucket. The catalogue is rebuildable from TMDB; what is not is the
     * accounts, shelves and reviews, and those are small and change slowly.
     */
    private const KEEP = 3;

    public function __construct(
        private readonly ObjectStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'How many dumps to retain', (string) self::KEEP)
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Name for this dump', 'feelm')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Show what is stored and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->storage->isConfigured()) {
            $io->error('No bucket configured. Set CONTABO_S3_KEY, _SECRET and _BUCKET.');

            return Command::FAILURE;
        }

        if ($input->getOption('list')) {
            return $this->list($io);
        }

        $keep = max(1, (int) $input->getOption('keep'));
        $key = sprintf(
            '%s%s-%s.dump',
            self::PREFIX,
            preg_replace('/[^a-z0-9-]/i', '', (string) $input->getOption('label')) ?: 'feelm',
            (new \DateTimeImmutable())->format('Y-m-d-His'),
        );

        $stream = fopen('php://stdin', 'rb');
        if (false === $stream) {
            $io->error('Could not read the dump from stdin.');

            return Command::FAILURE;
        }

        $started = microtime(true);
        $io->writeln(sprintf('Uploading %s …', $key));

        try {
            $this->storage->putStream($key, $stream, 'application/octet-stream');
        } catch (\Throwable $e) {
            $io->error('Upload failed: '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $stored = $this->storage->listKeys(self::PREFIX);
        $size = 0;
        foreach ($stored as $object) {
            if ($object['key'] === $key) {
                $size = $object['size'];
            }
        }

        /*
         * An empty object means pg_dump wrote nothing — a wrong password, a
         * container that was not up. Left in place it would push a real backup
         * out of the retention window, so it is removed and the run fails
         * loudly rather than reporting success over an empty file.
         */
        if (0 === $size) {
            $this->storage->delete($key);
            $io->error('The dump was empty — removed. Nothing has been backed up.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            '  %s MB in %ss',
            number_format($size / 1048576, 1),
            number_format(microtime(true) - $started, 1),
        ));

        // Pruned only after a good upload, so a failed run never costs a copy.
        $pruned = 0;
        foreach (\array_slice($stored, $keep) as $old) {
            $this->storage->delete($old['key']);
            $io->writeln('  removed '.$old['key']);
            ++$pruned;
        }

        $io->success(sprintf(
            'Backed up to %s. %d kept, %d removed.',
            $this->storage->bucket(),
            min(\count($stored), $keep),
            $pruned,
        ));

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io): int
    {
        $stored = $this->storage->listKeys(self::PREFIX);

        if ([] === $stored) {
            $io->warning('No backups stored.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Key', 'Size', 'Stored'],
            array_map(static fn (array $o) => [
                $o['key'],
                number_format($o['size'] / 1048576, 1).' MB',
                $o['modified']?->format('Y-m-d H:i') ?? '—',
            ], $stored),
        );

        return Command::SUCCESS;
    }
}
