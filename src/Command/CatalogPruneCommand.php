<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Hides the titles that can never render.
 *
 * ---- what it takes and why that is safe --------------------------------------
 *
 * No artwork, and nobody has ever rated it. A row like that cannot draw a
 * poster card, so the only place it can appear is as an empty box at the bottom
 * of a search — it is weight in every index and every count, and shows nothing
 * to anybody. 224,918 films are in that state.
 *
 * Deliberately *not* "has no IMDb id", which was the other candidate. That rule
 * would have taken 550,101 films, a third of which we simply never asked TMDB
 * about — IMDb ids arrive with the details backfill and 193,911 of them have
 * never been through it, so their missing id is our gap rather than their
 * absence. It would also have taken The Death and Return of Superman, Hulk vs.
 * Wolverine and a BTS concert film, none of which IMDb indexes the way TMDB
 * does. Votes and artwork are facts about the row in front of us; an IMDb id is
 * a fact about how far our own crawl got.
 *
 * ---- reversible ---------------------------------------------------------------
 *
 * Soft delete, the same mechanism the moderation tools use, so this is a
 * `--restore` away from undone — until purge-media reclaims the artwork, which
 * is why that is a separate command with its own decision.
 */
#[AsCommand(
    name: 'app:catalog:prune',
    description: 'Hide titles with no artwork and no ratings',
)]
final class CatalogPruneCommand extends Command
{
    /**
     * How many rows one UPDATE touches.
     *
     * A single statement over 224,918 rows takes one long lock and one enormous
     * WAL record; in batches the table stays usable throughout, which matters
     * because this runs against the live site.
     */
    private const BATCH = 5000;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually hide them; without this it only counts')
            ->addOption('restore', null, InputOption::VALUE_NONE, 'Undo a previous run')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict to one type', 'movie')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = (string) $input->getOption('type');
        $limit = $input->getOption('limit');
        $limit = null === $limit ? null : max(1, (int) $limit);

        if ($input->getOption('restore')) {
            return $this->restore($io, $type);
        }

        $io->title('Pruning '.$type.'s with no artwork and no ratings');

        $matching = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM works WHERE '.self::PREDICATE,
            ['type' => $type],
        );

        $io->writeln(sprintf('  %s would be hidden.', number_format($matching)));

        if (!$input->getOption('apply')) {
            $io->warning('Dry run. Nothing changed — pass --apply to commit.');
            $this->sample($io, $type);

            return Command::SUCCESS;
        }

        $hidden = 0;
        $started = microtime(true);

        // In batches, and re-selecting each time: the predicate excludes rows
        // already hidden, so each pass naturally takes the next slice.
        while (true) {
            $take = null === $limit ? self::BATCH : min(self::BATCH, $limit - $hidden);
            if ($take <= 0) {
                break;
            }

            $done = (int) $this->connection->executeStatement(
                'UPDATE works SET deleted_at = NOW()
                  WHERE id IN (SELECT id FROM works WHERE '.self::PREDICATE.' LIMIT '.$take.')',
                ['type' => $type],
            );

            if (0 === $done) {
                break;
            }

            $hidden += $done;
            $io->writeln(sprintf('  %s hidden…', number_format($hidden)));
        }

        $io->success(sprintf(
            '%s %ss hidden in %.1fs. Reversible with --restore.',
            number_format($hidden),
            $type,
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }

    /**
     * The rule, written once.
     *
     * `poster IS NULL` rather than "no mirror": the mirror only ever runs on
     * rows that had a TMDB URL, so a row with no poster column never had
     * artwork to begin with.
     */
    private const PREDICATE = "type = :type
              AND deleted_at IS NULL
              AND poster IS NULL
              AND COALESCE(vote_count, 0) = 0";

    private function restore(SymfonyStyle $io, string $type): int
    {
        /*
         * Only rows this command could have hidden. A title hidden by a
         * moderator for being 18+ also has no votes sometimes, and bringing it
         * back would undo a judgement this command never made — so `adult` is
         * excluded, and anything with artwork is too.
         */
        $back = (int) $this->connection->executeStatement(
            'UPDATE works SET deleted_at = NULL
              WHERE type = :type
                AND deleted_at IS NOT NULL
                AND adult = FALSE
                AND poster IS NULL
                AND COALESCE(vote_count, 0) = 0',
            ['type' => $type],
        );

        $io->success(sprintf('%s %ss restored.', number_format($back), $type));

        return Command::SUCCESS;
    }

    /** A look at what the rule actually catches, before it is trusted with it. */
    private function sample(SymfonyStyle $io, string $type): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT title, year, popularity FROM works
              WHERE '.self::PREDICATE.'
              ORDER BY popularity DESC NULLS LAST
              LIMIT 10',
            ['type' => $type],
        );

        if ([] === $rows) {
            return;
        }

        $io->section('The most popular of them — if these look like films you want, stop');
        $io->table(
            ['Title', 'Year', 'Popularity'],
            array_map(static fn (array $r) => [
                mb_substr((string) $r['title'], 0, 60),
                $r['year'] ?? '—',
                null === $r['popularity'] ? '—' : sprintf('%.2f', (float) $r['popularity']),
            ], $rows),
        );
    }
}
