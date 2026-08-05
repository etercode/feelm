<?php

namespace App\Command;

use App\Entity\Feedback;
use App\Service\Feedback\FeedbackImageStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reclaims the disk that screenshots take, without losing the reports.
 *
 * ---- what it deletes and what it keeps ---------------------------------------
 *
 * Only images, only on settled reports — done or declined — and only once they
 * have been settled for a while. The text, the status and the history stay for
 * ever: they are bytes, and they are the part anyone would ever look back at.
 * A screenshot has a short useful life, which ends when the thing it shows is
 * fixed or refused.
 *
 * Rows are kept and stamped rather than removed, so a purged report still reads
 * as "there were two screenshots here" instead of quietly becoming one that
 * never had any.
 *
 * NEW and ACCEPTED are never touched at any age. An accepted report is work
 * that has not been done yet, and its screenshots are the specification.
 */
#[AsCommand(
    name: 'app:feedback:purge',
    description: 'Delete screenshots from settled feedback to reclaim disk',
)]
final class FeedbackPurgeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FeedbackImageStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Days since the report was settled', '90')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would go and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('older-than'));
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', $days));

        /** @var list<\App\Entity\FeedbackImage> $images */
        $images = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(\App\Entity\FeedbackImage::class, 'i')
            ->innerJoin('i.feedback', 'f')
            ->andWhere('i.purgedAt IS NULL')
            ->andWhere('f.status IN (:settled)')
            ->andWhere('f.resolvedAt IS NOT NULL AND f.resolvedAt < :cutoff')
            ->setParameter('settled', [Feedback::STATUS_DONE, Feedback::STATUS_DECLINED])
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        if ([] === $images) {
            $io->success(\sprintf('Nothing settled before %s still has screenshots.', $cutoff->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $bytes = array_sum(array_map(static fn ($i) => $i->getBytes(), $images));

        if ($dryRun) {
            $io->success(\sprintf(
                '%s images (%s MB) would be purged.',
                number_format(\count($images)),
                number_format($bytes / 1048576, 1),
            ));

            return Command::SUCCESS;
        }

        foreach ($images as $image) {
            $this->storage->discard($image->getPath());
            $image->markPurged();
        }

        $this->entityManager->flush();

        // Empty month folders left behind once their last image goes. Cheap,
        // and it keeps the directory listing honest about what is still stored.
        foreach (glob($this->storage->root().'/*', \GLOB_ONLYDIR) ?: [] as $month) {
            if (false !== ($entries = @scandir($month)) && 2 === \count($entries)) {
                @rmdir($month);
            }
        }

        $io->success(\sprintf(
            '%s images purged, %s MB reclaimed.',
            number_format(\count($images)),
            number_format($bytes / 1048576, 1),
        ));

        return Command::SUCCESS;
    }
}
