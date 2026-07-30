<?php

namespace App\Command;

use App\Entity\ExternalId;
use App\Repository\WorkRepository;
use App\Service\Tmdb\TmdbClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills in IMDb ids for titles crawled before the crawler started storing them.
 *
 * New titles get theirs from the detail payload during the crawl; this is the
 * one-off catch-up, one TMDB call per title, and it only touches works that are
 * still missing an id.
 */
#[AsCommand(
    name: 'app:catalog:imdb-ids',
    description: 'Backfill IMDb ids from TMDB for works that do not have one yet',
)]
final class ImdbIdBackfillCommand extends Command
{
    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly WorkRepository $works,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many titles to look up', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->tmdb->isConfigured()) {
            $io->error('TMDB credentials are missing.');

            return Command::FAILURE;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $pending = $this->works->findMissingImdbId($limit);

        if ([] === $pending) {
            $io->success('Every title with a TMDB id already has an IMDb id.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Looking up %s titles…', number_format(\count($pending))));

        $found = 0;
        $missing = 0;

        foreach ($pending as $work) {
            $tmdbId = $work->getExternalId(ExternalId::SOURCE_TMDB);
            if (null === $tmdbId) {
                continue;
            }

            $path = 'series' === $work->getType() ? '/tv/' : '/movie/';
            $payload = $this->tmdb->get($path.$tmdbId.'/external_ids');
            $imdbId = $payload['imdb_id'] ?? null;

            if (!\is_string($imdbId) || 1 !== preg_match('/^tt\d{5,}$/', $imdbId)) {
                ++$missing;
                continue;
            }

            $identifier = new ExternalId(ExternalId::SOURCE_IMDB, $imdbId);
            $this->entityManager->persist($identifier);
            $work->addExternalId($identifier);
            ++$found;

            if (0 === $found % 50) {
                $this->entityManager->flush();
                $io->writeln(sprintf('  %s so far…', number_format($found)));
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            '%s ids stored, %s titles have none on TMDB.',
            number_format($found),
            number_format($missing),
        ));

        return Command::SUCCESS;
    }
}
