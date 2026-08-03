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
 * `pg_dump` lives in the database container and this runs in the PHP one; the
 * wrapper in deploy/backup-db.sh pipes one into the other.
 *
 * It is then spooled to a file before being sent. Piping straight through was
 * the first design and it is the better one right up until an upload fails:
 * Contabo dropped part 29 of the first real backup, and re-sending a part means
 * seeking back to it, which a pipe cannot do. Never a string in PHP either way
 * — gigabytes go through in chunks — but a few gigabytes of disk is what buys
 * the retry.
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

        $started = microtime(true);

        /*
         * Spooled to a file before it is sent, rather than piped straight
         * through. Contabo sheds load — the first real backup lost part 29 to a
         * response the SDK could not parse — and recovering means sending that
         * part again, which means seeking back to it. A pipe only goes
         * forwards. Disk is the price of being able to retry, and it is a few
         * gigabytes against 71 free.
         */
        $spool = tempnam(sys_get_temp_dir(), 'feelm-dump-');
        if (false === $spool) {
            $io->error('Could not create a temporary file for the dump.');

            return Command::FAILURE;
        }

        try {
            $io->writeln('Reading the dump …');
            $bytes = $this->spool($spool);

            if (0 === $bytes) {
                $io->error('The dump was empty. Nothing has been backed up.');

                return Command::FAILURE;
            }

            $io->writeln(sprintf('  %s MB read, uploading %s …', number_format($bytes / 1048576, 1), $key));
            $this->storage->putFile($key, $spool, 'application/octet-stream');
        } catch (\Throwable $e) {
            $io->error('Upload failed: '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            @unlink($spool);
        }

        $stored = $this->storage->listKeys(self::PREFIX);
        $size = 0;
        foreach ($stored as $object) {
            if ($object['key'] === $key) {
                $size = $object['size'];
            }
        }

        $io->writeln(sprintf(
            '  %s MB in %ss',
            number_format($size / 1048576, 1),
            number_format(microtime(true) - $started, 1),
        ));

        /*
         * Parts left behind by an upload that died. They belong to no object,
         * so nothing lists them, and they count against the quota until
         * somebody says otherwise. Six hours old, so an upload happening right
         * now is safe.
         */
        $abandoned = $this->storage->abortStaleUploads();
        if ($abandoned > 0) {
            $io->writeln(sprintf('  abandoned %d stale multipart upload(s)', $abandoned));
        }

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

    /**
     * Copies stdin to a file, returning how many bytes arrived.
     *
     * In chunks, because the dump is gigabytes and the point of a file is to
     * avoid holding it in memory — reading it into a string first would defeat
     * the whole arrangement.
     */
    private function spool(string $path): int
    {
        $in = fopen('php://stdin', 'rb');
        $out = fopen($path, 'wb');

        if (false === $in || false === $out) {
            throw new \RuntimeException('Could not open the dump for spooling.');
        }

        try {
            $bytes = stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        return false === $bytes ? 0 : $bytes;
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
