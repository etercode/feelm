<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Throws away everything the profiler has collected.
 *
 * The toolbar keeps a full copy of every request it sees — queries, their
 * parameters, the container, the whole response — and nothing ever removes
 * them. A few days of clicking around is hundreds of megabytes of var/cache,
 * and a profiler list long enough to be useless for finding the request you
 * actually care about.
 *
 * Registered in dev and test only, because that is where the profiler service
 * exists at all; asking for it in prod would fail to compile the container.
 */
#[When('dev')]
#[When('test')]
#[AsCommand(
    name: 'app:profiler:clear',
    description: 'Delete every collected profile, freeing the space they use',
)]
final class ProfilerClearCommand extends Command
{
    public function __construct(
        // By service id: the Profiler class is not aliased for autowiring,
        // because outside dev and test there is nothing to alias it to.
        #[Autowire(service: 'profiler')] private readonly Profiler $profiler,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('quiet-if-empty', null, InputOption::VALUE_NONE, 'Say nothing when there was nothing to clear');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $folder = $this->cacheDir.'/profiler';
        [$files, $bytes] = $this->measure($folder);

        if (0 === $files) {
            if (!$input->getOption('quiet-if-empty')) {
                $io->success('Nothing to clear — the profiler is already empty.');
            }

            return Command::SUCCESS;
        }

        $this->profiler->purge();

        [$left] = $this->measure($folder);
        if (0 !== $left) {
            $io->warning(sprintf('%d file(s) could not be removed. Check permissions on %s.', $left, $folder));

            return Command::FAILURE;
        }

        $io->success(sprintf('Cleared %s profile file(s), freeing %s.', number_format($files), $this->human($bytes)));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} how many files, and how many bytes
     */
    private function measure(string $folder): array
    {
        if (!is_dir($folder)) {
            return [0, 0];
        }

        $files = 0;
        $bytes = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                ++$files;
                $bytes += $file->getSize();
            }
        }

        return [$files, $bytes];
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < \count($units) - 1) {
            $size /= 1024;
            ++$i;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
