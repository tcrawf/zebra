<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class RolesCommand extends Command
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('roles')
            ->setDescription('Display all roles for a user by ID (uses current user if no ID provided)')
            ->addArgument('user-id', InputArgument::OPTIONAL, 'User ID (defaults to current user)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userIdArg = $input->getArgument('user-id');
        $user = null;

        // If no user ID provided, use current user
        if ($userIdArg === null || $userIdArg === '') {
            $user = $this->userRepository->getCurrentUser();
            if ($user === null) {
                $io->error(
                    'No user ID provided and no current user configured. ' .
                    'Use "zebra user --init" to set up a user, or provide a user ID.'
                );
                return Command::FAILURE;
            }
        } else {
            // Validate user ID is numeric
            if (!ctype_digit($userIdArg)) {
                $io->error("Invalid user ID: '{$userIdArg}'. User ID must be a number.");
                return Command::FAILURE;
            }

            $userId = (int) $userIdArg;

            // Fetch user by ID
            $user = $this->userRepository->getById($userId);
            if ($user === null) {
                $io->error("User with ID '{$userId}' not found.");
                return Command::FAILURE;
            }
        }

        $userId = $user->id;

        $roles = $user->roles;

        if (empty($roles)) {
            $io->info("User {$user->name} (ID: {$userId}) has no roles.");
            return Command::SUCCESS;
        }

        // Display user info
        $io->writeln("Roles for user: {$user->name} (ID: {$userId})");
        $io->writeln('');

        // Create and display table
        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Full Name', 'Type', 'Status', 'Parent ID']);

        foreach ($roles as $role) {
            $table->addRow([
                (string) $role->id,
                $role->name,
                $role->fullName,
                $role->type,
                $role->status,
                $role->parentId !== null ? (string) $role->parentId : '-',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
