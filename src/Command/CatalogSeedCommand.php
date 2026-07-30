<?php

namespace App\Command;

use App\Service\Catalog\CatalogWorkPersister;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:catalog:seed', description: 'Import optional data/catalog.json (games/books samples)')]
class CatalogSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CatalogWorkPersister $persister,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Delete existing items before import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $this->projectDir.'/data/catalog.json';

        if ($input->getOption('purge')) {
            $this->entityManager->createQuery('DELETE FROM App\Entity\Work i')->execute();
            $io->warning('Purged existing items.');
        }

        if (!is_readable($path)) {
            $io->note('No data/catalog.json — movies/series come from app:catalog:crawl.');

            return Command::SUCCESS;
        }

        /** @var array<string, mixed> $catalog */
        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $groups = [
            'movies' => 'movie',
            'upcoming' => 'movie',
            'series' => 'series',
            'games' => 'game',
            'books' => 'book',
        ];

        $count = 0;
        foreach ($groups as $key => $type) {
            $rows = $catalog[$key] ?? [];
            if (!\is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $row['type'] = $type;
                if ('upcoming' === $key) {
                    // upcoming is derived from the release date, nothing to set
                }
                $this->persister->persist($row);
                ++$count;
                if (0 === $count % 50) {
                    $this->persister->flush();
                    $this->persister->clear();
                }
            }
        }

        $this->persister->flush();
        $io->success(sprintf('Imported %d items from catalog.json.', $count));

        return Command::SUCCESS;
    }
}
