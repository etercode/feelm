<?php

namespace App\Command;

use App\Service\Notify\TelegramNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * How a shell script says something happened.
 *
 * The background work on this server is bash — nightly.sh, backup-db.sh, the
 * artwork mirror — and the bot token lives in the database so it can be edited
 * from the admin. Those two facts meet here: the scripts have no database
 * credentials and no business having any, so they call this instead of curl.
 *
 *   bin/console app:notify "Backup finished" --event=backup --fact="Size=412 MB"
 *   bin/console app:notify "Backup failed" --event=backup --fail
 *
 * Always exits 0. It is called from scripts that use `set -e` and from the tail
 * of a job that has already succeeded; a notifier that fails a green build is
 * a worse problem than a missed message.
 */
#[AsCommand(name: 'app:notify', description: 'Send a line to the configured Telegram chat')]
class NotifyCommand extends Command
{
    public function __construct(private readonly TelegramNotifier $telegram)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::REQUIRED, 'The heading')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'backup|nightly|deploy|error', 'error')
            ->addOption('fail', null, InputOption::VALUE_NONE, 'Mark this as a failure')
            ->addOption(
                'fact',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Extra "Label=value" lines, repeatable',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $facts = [];
        foreach ((array) $input->getOption('fact') as $pair) {
            [$label, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            if ('' !== trim($label)) {
                $facts[trim($label)] = trim($value);
            }
        }

        $event = (string) $input->getOption('event');
        if (!\array_key_exists($event, TelegramNotifier::EVENTS)) {
            $event = 'error';
        }

        $sent = $this->telegram->report(
            (string) $input->getArgument('message'),
            !$input->getOption('fail'),
            $facts,
            $event,
        );

        if (!$sent && $output->isVerbose()) {
            $output->writeln('<comment>not sent: '.($this->telegram->lastError() ?? 'switched off').'</comment>');
        }

        return Command::SUCCESS;
    }
}
