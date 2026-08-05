<?php

namespace App\Command;

use App\Service\Media\ObjectStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copies TMDB artwork into our own bucket.
 *
 * Resumable without keeping any state: a row leaves the queue by having a
 * mirror key, so stopping the run and starting it again picks up where it was.
 * The TMDB URL is never touched — see the note on Work::$posterMirror for why
 * both columns exist.
 *
 * Order matters within a row. The object is uploaded first and the column set
 * afterwards, so the worst a crash can leave behind is an object nobody points
 * at — which the next run simply overwrites. The other order would leave a row
 * pointing at an image that was never stored, and that is a broken poster.
 */
#[AsCommand(
    name: 'app:catalog:mirror-media',
    description: 'Copy TMDB posters and backdrops into the object storage bucket',
)]
final class CatalogMirrorMediaCommand extends Command
{
    /**
     * Returned when the queue is empty and there is nothing left to mirror.
     *
     * A runner loop needs to know when to stop, and the alternative was having
     * it grep this command's prose for a number — which is brittle in the
     * ordinary case and wrong in the interesting one: a `docker compose exec`
     * that fails while the container restarts prints nothing at all, and an
     * empty string parses as neither "done" nor "not done". An exit code says
     * it once, unambiguously, and survives the output being lost.
     */
    private const NOTHING_LEFT = 2;

    /**
     * Rows per batch.
     *
     * Every image is one download and one upload, and the upload crosses to
     * whichever region the bucket is in — 186ms each way for Singapore. Both
     * legs are latency-bound rather than bandwidth-bound, so the batch is a
     * concurrency window, and a large one is how the round trips overlap.
     */
    private const BATCH = 60;

    /**
     * Uploads in flight.
     *
     * Separate from the batch because the two halves have different limits:
     * downloads come from a CDN built to be hammered, uploads go to one
     * account on one Ceph cluster. At 60 it returned non-XML bodies the SDK
     * could not even parse into an error — the shape of being shed, not of
     * being refused — and 18 of 237 images were lost to it. Failures are
     * retried once, so this is the level at which retries stay rare rather
     * than the level at which nothing ever fails.
     */
    private const CONCURRENCY = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly ObjectStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many works to mirror this run', '2000')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Works fetched per round', (string) self::BATCH)
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Uploads in flight at once', (string) self::CONCURRENCY)
            ->addOption('posters-only', null, InputOption::VALUE_NONE, 'Skip backdrops — they are half the bytes and never on a card')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Report what is left and exit')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and report, storing nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->storage->isConfigured()) {
            $io->error('No bucket configured. Set CONTABO_S3_KEY, _SECRET and _BUCKET.');

            return Command::FAILURE;
        }

        $postersOnly = (bool) $input->getOption('posters-only');

        if ($input->getOption('status')) {
            return $this->status($io, $postersOnly);
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));
        $concurrency = max(1, (int) $input->getOption('concurrency'));
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Mirroring artwork to '.$this->storage->bucket());

        $started = microtime(true);
        $stored = 0;
        $bytes = 0;
        $failed = 0;
        $done = 0;

        while ($done < $limit) {
            $rows = $this->todo(min($batch, $limit - $done), $postersOnly);
            if ([] === $rows) {
                break;
            }
            $done += \count($rows);

            /*
             * Downloads first, all of them, then the uploads. Symfony's client
             * only starts a request when its response is read, so building the
             * whole list before touching any of it is what makes them run
             * together rather than one after another.
             */
            $wanted = [];
            foreach ($rows as $row) {
                foreach ($this->imagesOf($row, $postersOnly) as $column => $url) {
                    $key = $this->keyFor($url, $column);
                    if (null === $key) {
                        continue;
                    }
                    $wanted[] = [
                        'id' => (int) $row['id'],
                        'column' => $column,
                        'key' => $key,
                        'response' => $this->httpClient->request('GET', $url),
                        'url' => $url,
                    ];
                }
            }

            // Read every download, then hand the whole batch to one pool. The
            // uploads are the slow half and they have to overlap; doing them
            // inside this loop would serialise a 400ms round trip per image.
            $objects = [];
            $owners = [];

            /*
             * Sources TMDB will never serve again — see forget() below.
             *
             * @var list<array{id: int, column: string}>
             */
            $dead = [];

            foreach ($wanted as $item) {
                try {
                    $response = $item['response'];
                    if (200 !== $response->getStatusCode()) {
                        ++$failed;

                        // 404 only. A timeout or a 5xx is worth trying again;
                        // a file TMDB has stopped serving is not.
                        if (404 === $response->getStatusCode()) {
                            $dead[] = ['id' => $item['id'], 'column' => $item['column']];
                        }

                        continue;
                    }
                    $body = $response->getContent();
                    if ('' === $body) {
                        ++$failed;
                        continue;
                    }
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->writeln(sprintf('  <fg=red>✗</> %s: %s', $item['url'], $e->getMessage()));
                    continue;
                }

                $objects[] = [
                    'key' => $item['key'],
                    'body' => $body,
                    'contentType' => $response->getHeaders(false)['content-type'][0] ?? 'image/jpeg',
                ];
                $owners[] = ['id' => $item['id'], 'column' => $item['column'], 'key' => $item['key']];
                $bytes += \strlen($body);
            }

            /** @var array<int, array<string, string>> $updates */
            $updates = [];

            if ($dryRun) {
                $stored += \count($objects);
            } elseif ([] !== $objects) {
                $result = $this->storage->putMany($objects, $concurrency);

                /*
                 * One retry, at a quarter of the concurrency. What fails here
                 * fails from load rather than from being wrong — the same
                 * bytes to the same key — so the second attempt is worth more
                 * than leaving the row for a later run that will arrive with
                 * exactly as many requests in flight.
                 */
                if ([] !== $result['failed']) {
                    $again = array_map(static fn (int $i) => $objects[$i], array_keys($result['failed']));
                    $indexes = array_keys($result['failed']);
                    $second = $this->storage->putMany($again, max(1, intdiv($concurrency, 4)));

                    foreach ($second['stored'] as $position) {
                        $result['stored'][] = $indexes[$position];
                        unset($result['failed'][$indexes[$position]]);
                    }
                }

                // Only what actually landed gets a column set — see the class
                // note on why the object comes first and the row second.
                foreach ($result['stored'] as $index) {
                    $updates[$owners[$index]['id']][$owners[$index]['column']] = $owners[$index]['key'];
                    ++$stored;
                }
                foreach ($result['failed'] as $index => $why) {
                    ++$failed;
                    $io->writeln(sprintf('  <fg=red>✗</> %s: %s', $owners[$index]['key'], substr($why, 0, 90)));
                }

                if ([] !== $updates) {
                    $this->record($updates);
                }

                if ([] !== $dead) {
                    $this->forget($dead);
                }
            }

            $io->writeln(sprintf(
                '  %s stored, %s failed, %s MB, %s img/s',
                number_format($stored),
                number_format($failed),
                number_format($bytes / 1048576, 1),
                number_format($stored / max(0.001, microtime(true) - $started), 1),
            ));
        }

        $elapsed = microtime(true) - $started;
        $remaining = $this->remaining($postersOnly);
        $emptied = 0 === $remaining;

        $io->success(sprintf(
            '%s images (%s MB) in %s. %s failed. %s works still to do%s.',
            number_format($stored),
            number_format($bytes / 1048576, 1),
            $this->duration($elapsed),
            number_format($failed),
            number_format($remaining),
            $remaining > 0 && $stored > 0
                ? sprintf(' — about %s more at this rate', $this->duration($remaining * ($elapsed / $stored)))
                : '',
        ));

        return $emptied ? self::NOTHING_LEFT : Command::SUCCESS;
    }

    /**
     * The next works with artwork we do not hold.
     *
     * Keyset by id rather than OFFSET: the queue is a million rows and the
     * predicate is "not done yet", so an offset would walk further into the
     * table on every batch to reach rows the previous batch just left behind.
     *
     * @return list<array<string, mixed>>
     */
    private function todo(int $limit, bool $postersOnly): array
    {
        $where = $postersOnly
            ? "poster_mirror IS NULL AND poster LIKE 'http%'"
            : "(poster_mirror IS NULL AND poster LIKE 'http%')
               OR (backdrop_mirror IS NULL AND backdrop LIKE 'http%')";

        return $this->connection->executeQuery(
            "SELECT id, poster, backdrop, poster_mirror, backdrop_mirror
             FROM works
             WHERE {$where}
             ORDER BY popularity DESC NULLS LAST, id
             LIMIT {$limit}",
        )->fetchAllAssociative();
    }

    /**
     * Which of a row's images still need fetching.
     *
     * @return array<string, string> column name => TMDB URL
     */
    private function imagesOf(array $row, bool $postersOnly): array
    {
        $out = [];

        if (null === $row['poster_mirror'] && \is_string($row['poster']) && str_starts_with($row['poster'], 'http')) {
            $out['poster_mirror'] = $row['poster'];
        }

        if (!$postersOnly
            && null === $row['backdrop_mirror']
            && \is_string($row['backdrop'])
            && str_starts_with($row['backdrop'], 'http')) {
            $out['backdrop_mirror'] = $row['backdrop'];
        }

        return $out;
    }

    /**
     * The object key for a TMDB URL — "posters/w500/abc.jpg".
     *
     * TMDB file names are content-derived and already unique across the whole
     * CDN, so no sharding and no hashing of our own: two works sharing a poster
     * share an object, which is the correct outcome and saves the bytes.
     */
    private function keyFor(string $url, string $column): ?string
    {
        if (!preg_match('#^https?://image\.tmdb\.org/t/p/([^/]+)/([^/?\s]+)$#', $url, $m)) {
            return null;
        }

        $folder = 'poster_mirror' === $column ? 'posters' : 'backdrops';

        return sprintf('%s/%s/%s', $folder, $m[1], $m[2]);
    }

    /**
     * One statement per batch rather than one per image.
     *
     * A million single-row updates is a million WAL records and the table bloat
     * to match — and this table is already the one whose visibility map the
     * crawler page has to work around.
     *
     * @param array<int, array<string, string>> $updates
     */
    /**
     * Drop a source URL TMDB has stopped serving.
     *
     * ---- why this had to exist ---------------------------------------------
     *
     * The queue is "has a source URL and no mirror key", ordered by popularity.
     * A 404 left both of those true, so the same rows came back at the *head*
     * of every pass and were re-attempted for ever. It showed up as a failure
     * count that converged rather than a rate that varied: 199, 502, 535, 540,
     * 540, 540, 543 — a fixed set being retried, eating a quarter of every pass
     * and guaranteeing the run could never report itself complete.
     *
     * Clearing the URL is the honest answer rather than a suppression flag. The
     * file is genuinely gone: what we hold is a path TMDB replaced, and a card
     * pointing at it renders a broken image today. With it null the card falls
     * back to the placeholder, which is the truth.
     *
     * And it repairs itself. Artwork is the single most common TMDB edit — four
     * in five of the daily changes are image uploads — so the next time that
     * title is touched, the changes sync writes the new path and the nightly
     * mirror stores it.
     *
     * @param list<array{id: int, column: string}> $dead
     */
    private function forget(array $dead): void
    {
        // imagesOf() keys its result by the *mirror* column, because that is
        // what the caller is about to fill in. What has to be cleared is the
        // source beside it.
        foreach (['poster_mirror' => 'poster', 'backdrop_mirror' => 'backdrop'] as $from => $column) {
            $ids = array_values(array_unique(array_map(
                static fn (array $row) => $row['id'],
                array_filter($dead, static fn (array $row) => $row['column'] === $from),
            )));

            if ([] === $ids) {
                continue;
            }

            $this->connection->executeStatement(
                sprintf('UPDATE works SET %s = NULL WHERE id IN (:ids)', $column),
                ['ids' => $ids],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        }
    }

    private function record(array $updates): void
    {
        $rows = [];
        $params = [];
        $i = 0;

        foreach ($updates as $id => $columns) {
            $rows[] = sprintf('(:id%1$d, :p%1$d, :b%1$d)', $i);
            $params['id'.$i] = $id;
            $params['p'.$i] = $columns['poster_mirror'] ?? null;
            $params['b'.$i] = $columns['backdrop_mirror'] ?? null;
            ++$i;
        }

        $this->connection->executeStatement(
            'UPDATE works w SET
                poster_mirror = COALESCE(v.poster, w.poster_mirror),
                backdrop_mirror = COALESCE(v.backdrop, w.backdrop_mirror)
             FROM (VALUES '.implode(', ', $rows).') AS v(id, poster, backdrop)
             WHERE w.id = v.id::int',
            $params,
        );
    }

    private function remaining(bool $postersOnly): int
    {
        $where = $postersOnly
            ? "poster_mirror IS NULL AND poster LIKE 'http%'"
            : "(poster_mirror IS NULL AND poster LIKE 'http%')
               OR (backdrop_mirror IS NULL AND backdrop LIKE 'http%')";

        return (int) $this->connection->executeQuery("SELECT COUNT(*) FROM works WHERE {$where}")->fetchOne();
    }

    private function status(SymfonyStyle $io, bool $postersOnly): int
    {
        $counts = $this->connection->executeQuery(
            "SELECT
                COUNT(*) FILTER (WHERE poster LIKE 'http%')     AS posters,
                COUNT(*) FILTER (WHERE poster_mirror IS NOT NULL)   AS posters_done,
                COUNT(*) FILTER (WHERE backdrop LIKE 'http%')   AS backdrops,
                COUNT(*) FILTER (WHERE backdrop_mirror IS NOT NULL) AS backdrops_done
             FROM works",
        )->fetchAssociative() ?: [];

        $io->definitionList(
            ['Bucket' => $this->storage->bucket().' @ '.$this->storage->endpoint()],
            ['Posters mirrored' => sprintf('%s of %s', number_format((int) $counts['posters_done']), number_format((int) $counts['posters']))],
            ['Backdrops mirrored' => sprintf('%s of %s', number_format((int) $counts['backdrops_done']), number_format((int) $counts['backdrops']))],
            ['Works still to do' => number_format($this->remaining($postersOnly))],
        );

        return Command::SUCCESS;
    }

    private function duration(float $seconds): string
    {
        if ($seconds < 90) {
            return number_format($seconds).'s';
        }
        if ($seconds < 5400) {
            return number_format($seconds / 60).'m';
        }

        return number_format($seconds / 3600, 1).'h';
    }
}
