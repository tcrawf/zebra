<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\User\UserInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class UserCommand extends Command
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ConfigFileStorageInterface $configStorage
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('user')
            ->setDescription('Display current user information or initialize a new user')
            ->addOption('init', null, InputOption::VALUE_NONE, 'Initialize a new user')
            ->addOption(
                'email',
                'e',
                InputOption::VALUE_REQUIRED,
                'User email (for --init, non-interactive mode)'
            )
            ->addOption(
                'role',
                'r',
                InputOption::VALUE_REQUIRED,
                'Default role ID or name (for --init, non-interactive mode)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('init')) {
            return $this->initializeUser($input, $io);
        }

        return $this->displayCurrentUser($io);
    }

    /**
     * Initialize a new user by asking for email and default role.
     */
    private function initializeUser(InputInterface $input, SymfonyStyle $io): int
    {
        // Get email from option or prompt
        $email = $input->getOption('email');
        if ($email === null) {
            // Only prompt if input is interactive
            if (!$input->isInteractive()) {
                $io->error('Email is required. Use --email option for non-interactive mode.');
                return Command::FAILURE;
            }

            try {
                $emailQuestion = new Question('Email: ');
                $email = $io->askQuestion($emailQuestion);
            } catch (\Exception $e) {
                $io->error(
                    'Failed to read input. Use --email option for non-interactive mode: ' .
                    'zebra user --init --email=your@email.com'
                );
                return Command::FAILURE;
            }
        }

        if ($email === null || $email === '') {
            $io->error('Email is required.');
            return Command::FAILURE;
        }

        // Fetch user by email
        $user = $this->userRepository->getByEmail($email);
        if ($user === null) {
            $io->error("User with email '{$email}' not found.");
            return Command::FAILURE;
        }

        // Save user ID to config
        $this->configStorage->set('user.id', $user->id);
        $io->info("User found: {$user->name} (ID: {$user->id})");

        // Get role from option or prompt
        $roleIdentifier = $input->getOption('role');
        $selectedRole = null;

        if ($roleIdentifier !== null) {
            // Find role by ID or name
            $selectedRole = $this->findRoleByIdentifier($user, $roleIdentifier);
            if ($selectedRole === null) {
                $io->error("Role '{$roleIdentifier}' not found for this user.");
                return Command::FAILURE;
            }
        } else {
            // Prompt for default role
            $roles = $user->roles;
            if (empty($roles)) {
                $io->warning('No roles available for this user.');
                return Command::SUCCESS;
            }

            // Only prompt if input is interactive
            if (!$input->isInteractive()) {
                $io->error('Role is required. Use --role option for non-interactive mode.');
                return Command::FAILURE;
            }

            try {
                $roleNames = array_map(static fn(RoleInterface $role): string => $role->name, $roles);
                $roleQuestion = new ChoiceQuestion(
                    'Please select a default role:',
                    $roleNames
                );
                $selectedRoleName = $io->askQuestion($roleQuestion);

                // Find the selected role
                foreach ($roles as $role) {
                    if ($role->name === $selectedRoleName) {
                        $selectedRole = $role;
                        break;
                    }
                }
            } catch (\Exception $e) {
                $io->error(
                    'Failed to read input. Use --role option for non-interactive mode: ' .
                    'zebra user --init --email=your@email.com --role=RoleName'
                );
                return Command::FAILURE;
            }

            if ($selectedRole === null) {
                $io->error('Failed to find selected role.');
                return Command::FAILURE;
            }
        }

        // Save default role to config
        $this->configStorage->set('user.defaultRole.id', $selectedRole->id);
        $io->success("Default role set to: {$selectedRole->name}");

        return Command::SUCCESS;
    }

    /**
     * Find a role by ID or name for the given user.
     *
     * @param UserInterface $user
     * @param string $identifier Role ID (numeric) or name
     * @return RoleInterface|null
     */
    private function findRoleByIdentifier(UserInterface $user, string $identifier): ?RoleInterface
    {
        // Try to find by ID first
        if (ctype_digit($identifier)) {
            $roleId = (int) $identifier;
            foreach ($user->roles as $role) {
                if ($role->id === $roleId) {
                    return $role;
                }
            }
        }

        // Try to find by name (case-insensitive contains)
        return $user->findRoleByName($identifier);
    }

    /**
     * Display current user information.
     */
    private function displayCurrentUser(SymfonyStyle $io): int
    {
        $user = $this->userRepository->getCurrentUser();
        if ($user === null) {
            $io->warning('No user configured. Use "zebra user --init" to set up a user.');
            return Command::SUCCESS;
        }

        $defaultRole = $this->userRepository->getCurrentUserDefaultRole();

        $io->writeln("ID: {$user->id}");
        $io->writeln("Email: {$user->email}");
        if ($defaultRole !== null) {
            $io->writeln("Default Role: {$defaultRole->name}");
        } else {
            $io->writeln('Default Role: <fg=yellow>Not set</fg=yellow>');
        }

        return Command::SUCCESS;
    }
}
