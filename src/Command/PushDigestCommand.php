<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\Work;
use App\Repository\UserRepository;
use App\Repository\WorkRepository;
use App\Service\Notify\PushNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The morning digest: what the catalog turned up for you overnight.
 *
 *   0 * * * * /opt/feelm/deploy/push-digest.sh
 *
 * ---- why hourly, for a daily notification -----------------------------------
 *
 * "Nine in the morning" is a different instant for everybody. The nightly crawl
 * finds tonight's episodes at 02:00 server time, and pushing them then would
 * wake half the users on the continent. So this runs every hour and each run
 * asks a narrow question: which timezones are at 9am right now? Usually one or
 * two, sometimes none, and the users in them get their digest while everyone
 * else waits for their own turn.
 *
 * The alternative — one nightly job that sends everything at once — is one line
 * shorter and interrupts people at 3am. This is the whole reason User::timezone
 * was worth storing.
 *
 * ---- why it is safe to run twice --------------------------------------------
 *
 * users.push_digest_at. Cron is not a transaction: it fires twice under load,
 * it gets run by hand during a deploy, and on the night the clocks go back a
 * zone passes through 9am twice. Any of those without the guard is the same
 * digest delivered twice, which is exactly the sort of thing that gets an app
 * uninstalled.
 */
#[AsCommand(
    name: 'app:push:digest',
    description: 'Send the morning digest to whoever is at 9am local right now',
)]
class PushDigestCommand extends Command
{
    /** Local hour to send at. */
    private const SEND_HOUR = 9;

    /**
     * How recently a digest must have gone out for this run to skip somebody.
     * Twenty hours rather than twenty-four: a timezone whose offset shifts by an
     * hour must not be locked out of the following day's digest.
     */
    private const COOLDOWN_HOURS = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly WorkRepository $works,
        private readonly PushNotifier $push,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'hour',
                null,
                InputOption::VALUE_REQUIRED,
                'Pretend it is this local hour, for testing',
                (string) self::SEND_HOUR,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would be sent without sending or recording it',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $hour = max(0, min(23, (int) $input->getOption('hour')));

        if (!$dryRun && !$this->push->isEnabled()) {
            $io->writeln('Push is not configured or is switched off; nothing to do.');

            return Command::SUCCESS;
        }

        $zones = $this->zonesAtHour($hour);
        if ([] === $zones) {
            $io->writeln(sprintf('No timezone is at %02d:00 right now.', $hour));

            return Command::SUCCESS;
        }

        /*
         * Zones at the same hour are not necessarily on the same date — 9am in
         * Auckland and 9am in Honolulu are a day apart. So the work is grouped
         * by local date, not by zone, and each group asks the catalog what is
         * happening on its own "today".
         */
        $zonesByDate = [];
        foreach ($zones as $zone => $date) {
            $zonesByDate[$date][] = $zone;
        }

        $sentTo = 0;
        $reached = 0;

        foreach ($zonesByDate as $date => $zoneNames) {
            $candidates = $this->users->findForDigest($zoneNames, new \DateTimeImmutable(sprintf('-%d hours', self::COOLDOWN_HOURS)));
            if ([] === $candidates) {
                continue;
            }

            /** @var array<int, User> $byId */
            $byId = [];
            foreach ($candidates as $user) {
                $byId[$user->getId()] = $user;
            }

            $episodes = $this->episodesAiring(array_keys($byId), $date);
            $releases = $this->releasesOut(array_keys($byId), $date);

            foreach ($byId as $userId => $user) {
                // Filtered per person rather than per query: somebody who wants
                // episodes but not release day still gets a digest, just a
                // shorter one.
                $mine = $user->wantsPush(PushNotifier::KIND_EPISODE)
                    ? $this->worksById($episodes[$userId] ?? [])
                    : [];
                $theirs = $user->wantsPush(PushNotifier::KIND_RELEASE)
                    ? $this->worksById($releases[$userId] ?? [])
                    : [];

                if ([] === $mine && [] === $theirs) {
                    continue;
                }

                if ($dryRun) {
                    $io->writeln(sprintf(
                        '  %s (%s): %d episode(s), %d release(s)',
                        $user->getUsername(),
                        $user->getTimezone(),
                        \count($mine),
                        \count($theirs),
                    ));
                    ++$sentTo;
                    continue;
                }

                $devices = $this->push->digest($user, $mine, $theirs);
                $reached += $devices;
                ++$sentTo;

                // Recorded whether or not a device was reached. A person with
                // no phone registered has still had their turn today, and
                // retrying them every hour would only find no devices again.
                $user->setPushDigestAt(new \DateTimeImmutable());
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        $io->writeln($dryRun
            ? sprintf('Would send to %d people across %d zone(s).', $sentTo, \count($zones))
            : sprintf('Sent to %d people, reaching %d device(s).', $sentTo, $reached));

        return Command::SUCCESS;
    }

    /**
     * Every IANA zone whose local clock currently reads the given hour.
     *
     * Walking the full list is roughly four hundred cheap comparisons once an
     * hour, which is less work than any clever way of asking the same question
     * — and it gets DST right for free, because DateTimeZone already knows.
     *
     * @return array<string, string> zone name => its local Y-m-d
     */
    private function zonesAtHour(int $hour): array
    {
        $now = new \DateTimeImmutable();
        $matches = [];

        foreach (\DateTimeZone::listIdentifiers() as $name) {
            $local = $now->setTimezone(new \DateTimeZone($name));
            if ((int) $local->format('G') === $hour) {
                $matches[$name] = $local->format('Y-m-d');
            }
        }

        return $matches;
    }

    /**
     * Series a person is part-way through that have an episode airing today.
     *
     * Episode.airDate rather than Work.nextEpisodeAt: the nightly crawl moves
     * nextEpisodeAt on to the following episode as soon as it runs, so by the
     * time anyone is awake it already points past tonight. The episode rows
     * keep the actual dates.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<int>> user id => work ids
     */
    private function episodesAiring(array $userIds, string $date): array
    {
        return $this->group($this->connection->fetchAllAssociative(
            'SELECT DISTINCT e.user_id, w.id AS work_id
             FROM entries e
             JOIN works w ON w.id = e.work_id
             JOIN seasons s ON s.work_id = w.id
             JOIN episodes ep ON ep.season_id = s.id
             WHERE e.user_id IN (:ids)
               AND e.status = :status
               AND ep.air_date = :date
               AND w.deleted_at IS NULL',
            ['ids' => $userIds, 'status' => 'active', 'date' => $date],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        ));
    }

    /**
     * Wishlist titles whose release date is today.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<int>> user id => work ids
     */
    private function releasesOut(array $userIds, string $date): array
    {
        return $this->group($this->connection->fetchAllAssociative(
            'SELECT e.user_id, w.id AS work_id
             FROM entries e
             JOIN works w ON w.id = e.work_id
             WHERE e.user_id IN (:ids)
               AND e.status = :status
               AND w.release_date = :date
               AND w.deleted_at IS NULL',
            ['ids' => $userIds, 'status' => 'wishlist', 'date' => $date],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, list<int>>
     */
    private function group(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['user_id']][] = (int) $row['work_id'];
        }

        return $grouped;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Work>
     */
    private function worksById(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->works->findBy(['id' => $ids]);
    }
}
