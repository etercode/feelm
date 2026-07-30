<?php

namespace App\Command;

use App\Entity\Work;
use App\Entity\Season;
use App\Repository\WorkRepository;
use App\Service\Tmdb\TmdbMediaStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:localize-media',
    description: 'Download remote TMDB image URLs from the DB into public/media and rewrite paths',
)]
class CatalogLocalizeMediaCommand extends Command
{
    private const BATCH = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkRepository $works,
        private readonly TmdbMediaStore $media,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max items to process this run (0 = all)', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(0, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $updated = 0;
        $images = 0;
        $failed = 0;
        $processed = 0;
        $lastId = 0;

        $io->writeln('Localizing remote TMDB media in the database'.($dryRun ? ' (dry-run)' : '').'…');

        while (true) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $qb = $this->works->createQueryBuilder('i')
                ->andWhere('i.id > :lastId')
                ->setParameter('lastId', $lastId)
                ->orderBy('i.id', 'ASC')
                ->setMaxResults(self::BATCH);

            /** @var list<Work> $batch */
            $batch = $qb->getQuery()->getResult();
            if (!$batch) {
                break;
            }

            foreach ($batch as $work) {
                $lastId = (int) $work->getId();
                if ($limit > 0 && $processed >= $limit) {
                    break 2;
                }
                ++$processed;

                $changed = false;

                $poster = $work->getPoster();
                if ($this->media->isRemoteTmdb($poster)) {
                    $local = $this->media->mirror($poster);
                    if ($this->media->isRemoteTmdb($local)) {
                        ++$failed;
                        $io->writeln(sprintf('  ✗ #%d poster still remote', $work->getId()));
                    } elseif ($local !== $poster) {
                        ++$images;
                        $changed = true;
                        if (!$dryRun) {
                            $work->setPoster($local);
                        }
                    }
                }

                $backdrop = $work->getBackdrop();
                if ($this->media->isRemoteTmdb($backdrop)) {
                    $local = $this->media->mirror($backdrop);
                    if ($this->media->isRemoteTmdb($local)) {
                        ++$failed;
                        $io->writeln(sprintf('  ✗ #%d backdrop still remote', $work->getId()));
                    } elseif ($local !== $backdrop) {
                        ++$images;
                        $changed = true;
                        if (!$dryRun) {
                            $work->setBackdrop($local);
                        }
                    }
                }

                $details = $work->getDetails();
                $detailsChanged = false;
                if (isset($details['cast']) && \is_array($details['cast'])) {
                    foreach ($details['cast'] as $i => $person) {
                        if (!\is_array($person)) {
                            continue;
                        }
                        $photo = isset($person['photo']) ? (string) $person['photo'] : null;
                        if (!$this->media->isRemoteTmdb($photo)) {
                            continue;
                        }
                        $local = $this->media->mirror($photo);
                        if ($this->media->isRemoteTmdb($local)) {
                            ++$failed;
                            continue;
                        }
                        if ($local !== $photo) {
                            ++$images;
                            $details['cast'][$i]['photo'] = $local;
                            $detailsChanged = true;
                        }
                    }
                }
                if ($detailsChanged) {
                    $changed = true;
                    if (!$dryRun) {
                        // New array instance so Doctrine detects the JSON change.
                        $work->setDetails($details);
                    }
                }

                foreach ($work->getSeasons() as $season) {
                    if ($this->localizeSeason($season, $dryRun, $images, $failed, $io)) {
                        $changed = true;
                    }
                }

                if ($changed) {
                    ++$updated;
                    $io->writeln(sprintf('  ✓ #%d %s', $work->getId(), $work->getTitle()));
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
            // Clear only after the whole batch was flushed, then load the next page by id.
            $this->entityManager->clear();
        }

        $io->success(sprintf(
            'Done. scanned=%d items touched=%d images localized=%d failures=%d%s',
            $processed,
            $updated,
            $images,
            $failed,
            $dryRun ? ' (dry-run, nothing written)' : '',
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function localizeSeason(
        Season $season,
        bool $dryRun,
        int &$images,
        int &$failed,
        SymfonyStyle $io,
    ): bool {
        $changed = false;
        $poster = $season->getPoster();
        if ($this->media->isRemoteTmdb($poster)) {
            $local = $this->media->mirror($poster);
            if ($this->media->isRemoteTmdb($local)) {
                ++$failed;
                $io->writeln(sprintf('  ✗ season #%d poster still remote', $season->getId()));
            } elseif ($local !== $poster) {
                ++$images;
                $changed = true;
                if (!$dryRun) {
                    $season->setPoster($local);
                }
            }
        }

        return $changed;
    }
}
