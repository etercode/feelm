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
 * Hides titles in bulk, by rule.
 *
 * ---- blank -------------------------------------------------------------------
 *
 * No artwork, and nobody has ever rated it. A row like that cannot draw a
 * poster card, so the only place it can appear is as an empty box at the bottom
 * of a search — weight in every index and every count, showing nothing to
 * anybody. 224,918 films were in that state and are now hidden.
 *
 * ---- unrated -----------------------------------------------------------------
 *
 * Nobody has rated it and nobody is looking at it. This is the rule that
 * reaches the direct-to-video pornography the blank rule left behind — those
 * rows have artwork and an IMDb id, so neither other rule touches them, but no
 * human being has ever voted on one.
 *
 * It is a proxy, and an imprecise one: it also takes two hundred thousand
 * obscure but legitimate films. If the goal is specifically adult content, the
 * propagation approach through performers and studios is the accurate tool and
 * this is the blunt one.
 *
 * ---- no-imdb -----------------------------------------------------------------
 *
 * TMDB holds no IMDb id for it. A blunter rule, and it is worth being honest
 * about what it costs: it takes The Death and Return of Superman, Hulk vs.
 * Wolverine, a BTS concert film and every Golden Globes ceremony, because IMDb
 * does not index direct-to-video, TV specials and award shows the way TMDB
 * does. It also does nothing for adult content, which was the question that
 * first raised it — adult titles are *less* likely to be missing an IMDb id
 * (34.7%) than the catalogue average (45.4%).
 *
 * It is a catalogue-size decision, not a quality signal, and it is the
 * operator's to make. See predicate() for the one part of it that is not a
 * judgement call but a mistake: a title whose details were never fetched has no
 * IMDb id because nobody asked.
 *
 * ---- reversible ---------------------------------------------------------------
 *
 * Soft delete, the same mechanism the moderation tools use, so this is a
 * `--restore` away from undone — until purge-media reclaims the artwork, which
 * is why that is a separate command with its own decision.
 */
#[AsCommand(
    name: 'app:catalog:prune',
    description: 'Hide titles in bulk by rule (blank | unrated | no-imdb)',
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
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'blank, unrated or no-imdb', 'blank')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually hide them; without this it only counts')
            ->addOption('restore', null, InputOption::VALUE_NONE, 'Undo a previous run')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict to one type', 'movie')
            ->addOption('include-unchecked', null, InputOption::VALUE_NONE, 'no-imdb only: also take titles whose details were never fetched')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = (string) $input->getOption('type');
        $rule = (string) $input->getOption('rule');
        $unchecked = (bool) $input->getOption('include-unchecked');
        $limit = $input->getOption('limit');
        $limit = null === $limit ? null : max(1, (int) $limit);

        try {
            $where = $this->predicate($rule, $unchecked);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($input->getOption('restore')) {
            return $this->restore($io, $type, $where);
        }

        $io->title(sprintf('Pruning %ss by rule "%s"', $type, $rule));

        $matching = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM works WHERE '.$where,
            ['type' => $type],
        );

        $io->writeln(sprintf('  %s would be hidden.', number_format($matching)));

        if (!$input->getOption('apply')) {
            $io->warning('Dry run. Nothing changed — pass --apply to commit.');
            $this->sample($io, $type, $where);

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
                  WHERE id IN (SELECT id FROM works WHERE '.$where.' LIMIT '.$take.')',
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
     * The rules, each written once so the count, the sample, the update and the
     * restore cannot disagree about what they are acting on.
     *
     * `blank` — no artwork and no ratings. `poster IS NULL` rather than "no
     * mirror": the mirror only ever runs on rows that had a TMDB URL, so a row
     * with no poster column never had artwork to begin with.
     *
     * `no-imdb` — TMDB holds no IMDb id for it.
     *
     * ---- the trap in no-imdb -----------------------------------------------
     *
     * An IMDb id arrives with the details backfill. A title that has never been
     * through it has no id because *we never asked*, not because IMDb has no
     * entry — and on production that is 113,014 titles. Deleting those is
     * deleting on missing data, so `details_synced_at IS NOT NULL` is part of
     * the rule and --include-unchecked is what removes it.
     */
    private function predicate(string $rule, bool $includeUnchecked): string
    {
        $base = 'type = :type AND deleted_at IS NULL';

        return match ($rule) {
            'blank' => $base." AND poster IS NULL AND COALESCE(vote_count, 0) = 0",

            /*
             * Nobody has rated it and nobody is looking at it.
             *
             * The popularity floor is not decoration. "No votes" alone reads as
             * a dead title and is not: an unreleased film has no votes because
             * it is not out yet, and the rule without a floor takes Avengers:
             * Doomsday, Avengers: Secret Wars and Ramayana along with the
             * landfill. TMDB's popularity is a measure of people *looking*, so
             * an anticipated film scores 20-70 with zero votes while a
             * direct-to-video title from 2005 sits at 0.
             *
             * Two independent signals of "nobody has ever cared", rather than
             * one signal that also means "not out yet".
             */
            'unrated' => $base.' AND COALESCE(vote_count, 0) = 0 AND COALESCE(popularity, 0) < 1',
            'no-imdb' => $base
                .($includeUnchecked ? '' : ' AND details_synced_at IS NOT NULL')
                ." AND NOT EXISTS (
                    SELECT 1 FROM external_ids x WHERE x.work_id = works.id AND x.source = 'imdb'
                  )",
            default => throw new \InvalidArgumentException('Unknown rule: '.$rule),
        };
    }

    private function restore(SymfonyStyle $io, string $type, string $where): int
    {
        /*
         * Only rows this command could have hidden. A title hidden by a
         * moderator for being 18+ also has no votes sometimes, and bringing it
         * back would undo a judgement this command never made — so `adult` is
         * excluded, and anything with artwork is too.
         */
        $back = (int) $this->connection->executeStatement(
            'UPDATE works SET deleted_at = NULL WHERE adult = FALSE AND '
                .str_replace('deleted_at IS NULL', 'deleted_at IS NOT NULL', $where),
            ['type' => $type],
        );

        $io->success(sprintf('%s %ss restored.', number_format($back), $type));

        return Command::SUCCESS;
    }

    /** A look at what the rule actually catches, before it is trusted with it. */
    private function sample(SymfonyStyle $io, string $type, string $where): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT title, year, popularity FROM works
              WHERE '.$where.'
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
