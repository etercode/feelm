<?php

namespace App\Command;

use App\Service\Catalog\ImdbRatingsImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:imdb-ratings',
    description: 'Import IMDb ratings from IMDb\'s published dataset for titles we hold',
)]
final class ImdbRatingsCommand extends Command
{
    public function __construct(
        private readonly ImdbRatingsImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'file',
            null,
            InputOption::VALUE_REQUIRED,
            'Use an already downloaded title.ratings.tsv.gz instead of fetching it',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $file = $input->getOption('file');
        if (null !== $file && !is_readable((string) $file)) {
            $io->error(sprintf('Cannot read %s.', $file));

            return Command::FAILURE;
        }

        $result = $this->importer->import(
            null !== $file ? (string) $file : null,
            static fn (string $message) => $io->writeln($message),
        );

        $io->success(sprintf(
            '%s of %s titles rated on IMDb (%s dataset rows scanned) in %.1fs.',
            number_format($result['matched']),
            number_format($result['known']),
            number_format($result['scanned']),
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }
}
