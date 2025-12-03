<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\ActivityOrProjectAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetDateHelper;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class TimesheetCreateCommand extends Command
{
    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $timesheetRepository,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly ActivityOrProjectAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('timesheet:create')
            ->setDescription('Create a new timesheet entry')
            ->addArgument(
                'activity',
                InputArgument::REQUIRED,
                'Activity alias or ID'
            )
            ->addArgument(
                'description',
                InputArgument::REQUIRED,
                'Timesheet description'
            )
            ->addArgument(
                'time',
                InputArgument::REQUIRED,
                'Time in hours (must be multiple of 0.25, e.g., 0.25, 0.5, 1.0, 1.25)'
            )
            ->addOption(
                'date',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Date for the timesheet (YYYY-MM-DD format, defaults to today)',
                null
            )
            ->addOption(
                'client-description',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Client-facing description',
                null
            )
            ->addOption(
                'role',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Role ID or name'
            )
            ->addOption(
                'individual',
                'i',
                InputOption::VALUE_NONE,
                'Mark as individual action (does not require a role)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Resolve activity
        $activityIdentifier = $input->getArgument('activity');
        $activity = $this->resolveActivity($activityIdentifier, $io);

        if ($activity === null) {
            $io->error("Activity '{$activityIdentifier}' not found");
            return Command::FAILURE;
        }

        // Parse date using centralized helper
        try {
            $date = TimesheetDateHelper::parseDateInput($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Parse time
        $timeStr = $input->getArgument('time');
        $time = (float) $timeStr;

        // Validate time is a multiple of 0.25
        $remainder = fmod($time * 100, 25);
        if (abs($remainder) > 0.0001) {
            $io->error("Time must be a multiple of 0.25, got: {$time}");
            return Command::FAILURE;
        }

        if ($time <= 0) {
            $io->error("Time must be positive, got: {$time}");
            return Command::FAILURE;
        }

        // Get description
        $description = $input->getArgument('description');
        if (empty(trim($description))) {
            $io->error('Description cannot be empty');
            return Command::FAILURE;
        }

        // Get client description
        $clientDescription = $input->getOption('client-description');
        if ($clientDescription !== null && empty(trim($clientDescription))) {
            $clientDescription = null;
        }

        // Resolve role
        $isIndividual = $input->getOption('individual') === true;
        $role = $isIndividual ? null : $this->resolveRole($input, $io, $activity);

        if (!$isIndividual && $role === null) {
            $io->error('Either --role must be provided or --individual flag must be set');
            return Command::FAILURE;
        }

        try {
            // Create timesheet locally (without zebraId initially)
            $timesheet = TimesheetFactory::create(
                $activity,
                $description,
                $clientDescription,
                $time,
                $date,
                $role,
                $isIndividual,
                [], // No frame UUIDs for manually created timesheets
                null, // No zebraId initially
                null // updatedAt will default to current time
            );

            // Save to local repository
            $this->timesheetRepository->save($timesheet);

            $io->success('Timesheet created successfully');
            $io->writeln("<info>UUID: {$timesheet->uuid}</info>");
            $io->writeln("<info>Activity: {$timesheet->activity->name}</info>");
            $io->writeln("<info>Project: {$timesheet->activity->projectEntityKey->toString()}</info>");
            $io->writeln("<info>Date: {$date->format('Y-m-d')}</info>");
            $io->writeln("<info>Time: {$time} hours</info>");
            $io->writeln("<info>Description: {$description}</info>");
            if ($clientDescription !== null) {
                $io->writeln("<info>Client Description: {$clientDescription}</info>");
            }
            if ($isIndividual) {
                $io->writeln("<info>Type: Individual Action</info>");
            } else {
                $io->writeln("<info>Role: {$role->name}</info>");
            }
            $io->writeln("<info>Status: Local (not yet synced to Zebra)</info>");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Resolve an activity by alias, ID, or name search.
     *
     * @param string $identifier Activity alias, ID, or search string
     * @param SymfonyStyle $io
     * @return ActivityInterface|null
     */
    private function resolveActivity(string $identifier, SymfonyStyle $io): ?ActivityInterface
    {
        // First, try to find by exact activity alias match
        $activity = $this->activityRepository->getByAlias($identifier);
        if ($activity !== null) {
            return $activity;
        }

        // If not found by alias, try to find by exact activity ID match
        if (ctype_digit($identifier)) {
            $entityKey = EntityKey::zebra((int) $identifier);
            $activity = $this->activityRepository->get($entityKey);
            if ($activity !== null) {
                return $activity;
            }
        }

        // If no exact match, search activities by alias
        $activityMatches = $this->activityRepository->searchByAlias($identifier);

        if (empty($activityMatches)) {
            return null;
        }

        if (count($activityMatches) === 1) {
            return $activityMatches[0];
        }

        // Multiple matches found, prompt user to select
        $activityOptions = [];
        $activityMap = [];

        foreach ($activityMatches as $activity) {
            $displayParts = [];
            $displayParts[] = $activity->name;
            if ($activity->alias !== null) {
                $displayParts[] = "(alias: {$activity->alias})";
            }

            $displayName = implode(' - ', $displayParts);
            $activityOptions[] = $displayName;
            $activityMap[$displayName] = $activity;
        }

        $question = new ChoiceQuestion(
            "Multiple activities found matching '{$identifier}'. Please select one:",
            $activityOptions
        );
        $selectedOption = $io->askQuestion($question);

        return $activityMap[$selectedOption] ?? null;
    }

    /**
     * Resolve a role by ID or name.
     * If no role is provided, uses the default role for the current user.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @param ActivityInterface $activity The activity (for potential future use)
     * @return RoleInterface|null
     */
    private function resolveRole(InputInterface $input, SymfonyStyle $io, ActivityInterface $activity): ?RoleInterface
    {
        $roleIdentifier = $input->getOption('role');

        // If no role provided, use default role
        if ($roleIdentifier === null) {
            $role = $this->userRepository->getCurrentUserDefaultRole();
            if ($role === null) {
                $io->error('No default role configured. Please provide --role or set a default role.');
                return null;
            }
            $io->info("Using default role: {$role->name}");
            return $role;
        }

        $user = $this->userRepository->getCurrentUser();
        if ($user === null) {
            $io->error('No user ID found in config. Please run "zebra user --init" to set up a user.');
            return null;
        }

        // Try to find by ID first
        if (ctype_digit($roleIdentifier)) {
            $roleId = (int) $roleIdentifier;
            foreach ($user->roles as $role) {
                if ($role->id === $roleId) {
                    return $role;
                }
            }
            $io->error("Role with ID '{$roleIdentifier}' not found for current user.");
            return null;
        }

        // Try to find by name
        $matchingRoles = $user->findAllRolesByName($roleIdentifier);

        if (empty($matchingRoles)) {
            $io->error("No role found matching '{$roleIdentifier}'.");
            return null;
        }

        if (count($matchingRoles) === 1) {
            return $matchingRoles[0];
        }

        // Multiple matches, ask user to choose
        $roleNames = array_map(static fn(RoleInterface $role): string => $role->name, $matchingRoles);
        $question = new ChoiceQuestion(
            "Multiple roles found matching '{$roleIdentifier}'. Please select one:",
            $roleNames
        );
        $selectedRoleName = $io->askQuestion($question);

        foreach ($matchingRoles as $role) {
            if ($role->name === $selectedRoleName) {
                return $role;
            }
        }

        return null;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('activity')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}
