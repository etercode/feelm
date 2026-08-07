<?php

namespace App\Command;

use App\Search\WorkSearch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the caches search reads on the hot path.
 *
 * Only one so far: the map of which genres hold which types of work, which
 * search consults before running an impossible genre/type combination. Building
 * it is a DISTINCT over every work_genre row — two seconds on the server — and
 * without this it is rebuilt by whichever visitor happens to arrive after it
 * expires, or after a deploy clears the pool.
 *
 * Belongs at the end of the nightly, after the crawls that could have changed
 * the answer.
 */
#[AsCommand(
    name: 'app:search:warm',
    description: 'Rebuild the caches the search hot path depends on',
)]
class SearchWarmCommand extends Command
{
    public function __construct(private readonly WorkSearch $search)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $started = microtime(true);
        $pairs = $this->search->warmGenreTypes();

        $io->success(sprintf(
            'Genre/type map rebuilt: %d pairs in %.1fs.',
            $pairs,
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }
}
