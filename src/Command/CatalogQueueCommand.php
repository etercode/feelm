<?php

namespace App\Command;

use App\Service\Tmdb\TmdbDiscoverQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dates the crawl queue for a span of years, so `app:catalog:crawl-all --since`
 * has something to filter on. Run it until it says it is done, then crawl.
 */
#[AsCommand(
    name: 'app:catalog:queue',
    description: 'Fill the crawl queue with release years from a given year onwards',
)]
final class CatalogQueueCommand extends Command
{
    public function __construct(
        private readonly TmdbDiscoverQueue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'First release year to queue', '1990')
            ->addOption('requests', null, InputOption::VALUE_REQUIRED, 'API requests to spend this run', '400');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $since = max(1874, (int) $input->getOption('since'));
        $budget = max(1, (int) $input->getOption('requests'));

        $started = microtime(true);
        $result = $this->queue->fill($since, $budget, static fn (string $line) => $io->writeln($line));
        $elapsed = microtime(true) - $started;

        $io->success(sprintf(
            '%s ids dated across %s months in %.0fs. %s%s waiting to be crawled.',
            number_format($result['queued']),
            number_format($result['months']),
            $elapsed,
            number_format($this->queue->remaining($since)),
            $result['done'] ? ' — queue complete' : ' so far, cursor at '.$result['cursor'],
        ));

        return Command::SUCCESS;
    }
}
