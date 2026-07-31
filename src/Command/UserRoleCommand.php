<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grants and revokes roles from the command line.
 *
 * The admin UI can do this too, but only to somebody who is already an
 * administrator — and nothing in the application has ever written the roles
 * column, so without this there is no way to create the first one short of
 * hand-editing json in psql.
 *
 * It is also the way back in if the last administrator is ever locked out,
 * which is why the UI's "you cannot remove the last admin" rule is not
 * repeated here.
 */
#[AsCommand(
    name: 'app:user:role',
    description: 'Grant or revoke a role on an account',
)]
final class UserRoleCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Whose roles to change')
            ->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role to add (ROLE_ADMIN, ROLE_MODERATOR)')
            ->addOption('revoke', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role to remove')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Show everybody who holds a role and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            return $this->listHolders($io);
        }

        $username = $input->getArgument('username');
        if (!\is_string($username) || '' === $username) {
            $io->error('Give a username, or pass --list.');

            return Command::INVALID;
        }

        $user = $this->users->findOneActiveByUsername($username);
        if (null === $user) {
            $io->error(sprintf('No live account called "%s".', $username));

            return Command::FAILURE;
        }

        /** @var list<string> $grant */
        $grant = array_map(strtoupper(...), $input->getOption('grant'));
        /** @var list<string> $revoke */
        $revoke = array_map(strtoupper(...), $input->getOption('revoke'));

        foreach ([...$grant, ...$revoke] as $role) {
            if (!\in_array($role, User::ASSIGNABLE_ROLES, true)) {
                $io->error(sprintf('"%s" is not assignable. Choose from: %s.', $role, implode(', ', User::ASSIGNABLE_ROLES)));

                return Command::INVALID;
            }
        }

        if ([] === $grant && [] === $revoke) {
            $io->writeln(sprintf('<info>%s</info> holds: %s', $username, $this->describe($user)));

            return Command::SUCCESS;
        }

        $before = $user->getGrantedRoles();
        $after = array_values(array_diff([...$before, ...$grant], $revoke));

        if ($after === $before) {
            $io->writeln(sprintf('Nothing to do — <info>%s</info> already holds: %s', $username, $this->describe($user)));

            return Command::SUCCESS;
        }

        $user->setRoles($after);
        $this->entityManager->flush();

        $io->success(sprintf('%s now holds: %s', $username, $this->describe($user)));

        return Command::SUCCESS;
    }

    private function listHolders(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->users->findWithAnyRole() as $user) {
            $rows[] = [$user->getUsername(), $user->getName(), implode(', ', $user->getGrantedRoles())];
        }

        if ([] === $rows) {
            $io->warning('Nobody holds a role. Grant one: app:user:role <username> --grant=ROLE_ADMIN');

            return Command::SUCCESS;
        }

        $io->table(['Username', 'Name', 'Roles'], $rows);

        return Command::SUCCESS;
    }

    private function describe(User $user): string
    {
        $roles = $user->getGrantedRoles();

        return [] === $roles ? 'nothing beyond ROLE_USER' : implode(', ', $roles);
    }
}
