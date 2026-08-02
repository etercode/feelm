<?php

namespace App\Service\Tmdb;

use App\Entity\ExternalId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Which titles TMDB touched recently, narrowed to the ones we hold.
 *
 * /tv/changes answers "what changed" for the whole of TMDB, not for us — a few
 * thousand ids on a normal day, most of them titles we have never heard of. The
 * useful half of the job is the intersection, which is a database question, so
 * it happens here rather than in the command.
 *
 * TMDB will not look back further than 14 days, and the window is inclusive of
 * both ends. Asking for more than that silently returns the last 14, which is
 * worth knowing before someone tries to use this to backfill a month.
 */
final class TmdbChanges
{
    /** How far back TMDB will answer for. */
    public const MAX_DAYS = 14;

    /** TMDB pages these 100 at a time; the ceiling stops a runaway loop. */
    private const MAX_PAGES = 500;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly Connection $connection,
    ) {
    }

    /**
     * TMDB ids of series that changed in the window and that we already store.
     *
     * @param callable(string): void|null $log
     *
     * @return list<int>
     */
    public function changedSeries(int $days, ?callable $log = null): array
    {
        $say = $log ?? static function (string $message): void {};

        $days = max(1, min(self::MAX_DAYS, $days));
        $end = new \DateTimeImmutable('today');
        $start = $end->modify(sprintf('-%d days', $days));

        $changed = $this->fetchChangedIds($start, $end, $say);
        if ([] === $changed) {
            return [];
        }

        $ours = $this->weStore($changed);
        $say(sprintf(
            '%s changed on TMDB since %s, %s of them ours.',
            number_format(\count($changed)),
            $start->format('Y-m-d'),
            number_format(\count($ours)),
        ));

        return $ours;
    }

    /**
     * @param callable(string): void $say
     *
     * @return list<int>
     */
    private function fetchChangedIds(\DateTimeImmutable $start, \DateTimeImmutable $end, callable $say): array
    {
        $ids = [];
        $page = 1;

        do {
            $response = $this->tmdb->get('/tv/changes', [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'page' => $page,
            ]);

            if (null === $response) {
                $say(sprintf('TMDB did not answer for page %d; stopping there.', $page));
                break;
            }

            foreach ($response['results'] ?? [] as $row) {
                if (isset($row['id'])) {
                    $ids[] = (int) $row['id'];
                }
            }

            $total = min((int) ($response['total_pages'] ?? 1), self::MAX_PAGES);
            ++$page;
        } while ($page <= $total);

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $tmdbIds
     *
     * @return list<int>
     */
    private function weStore(array $tmdbIds): array
    {
        $ours = [];

        // Chunked because the id list is unbounded and Postgres has a limit on
        // bound parameters.
        foreach (array_chunk($tmdbIds, 1000) as $chunk) {
            $rows = $this->connection->executeQuery(
                'SELECT external_id FROM external_ids WHERE source = :source AND external_id IN (:ids)',
                [
                    'source' => ExternalId::SOURCE_TMDB_TV,
                    'ids' => array_map('strval', $chunk),
                ],
                ['ids' => ArrayParameterType::STRING],
            )->fetchFirstColumn();

            foreach ($rows as $value) {
                $ours[] = (int) $value;
            }
        }

        return $ours;
    }
}
