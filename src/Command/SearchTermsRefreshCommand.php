<?php

namespace App\Command;

use App\Search\SearchTermsIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search:refresh-terms',
    description: 'Rebuild the "did you mean" lexicon from titles, people and genres',
)]
final class SearchTermsRefreshCommand extends Command
{
    public function __construct(
        private readonly SearchTermsIndex $terms,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $count = $this->terms->rebuild();

        $io->success(sprintf(
            '%s search terms indexed in %.1fs.',
            number_format($count),
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }
}
