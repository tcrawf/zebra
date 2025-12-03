<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetMergeCommand extends Command
{
    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $timesheetRepository,
        private readonly TimesheetAutocompletion $timesheetAutocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('timesheet:merge')
            ->setDescription('Merge multiple timesheets into one')
            ->addArgument(
                'timesheets',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Timesheet UUIDs to merge (at least 2 required)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip confirmation prompts'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force') === true;

        $uuidStrings = $input->getArgument('timesheets');

        if (count($uuidStrings) < 2) {
            $io->error('At least 2 timesheet UUIDs are required for merging');
            return Command::FAILURE;
        }

        // Load all timesheets
        $timesheets = [];
        $notFound = [];

        foreach ($uuidStrings as $uuidString) {
            $timesheet = $this->timesheetRepository->get($uuidString);
            if ($timesheet === null) {
                $notFound[] = $uuidString;
            } else {
                $timesheets[] = $timesheet;
            }
        }

        if (!empty($notFound)) {
            $io->error('The following timesheets were not found: ' . implode(', ', $notFound));
            return Command::FAILURE;
        }

        // Validate all timesheets have the same activity
        $firstActivityKey = $timesheets[0]->activity->entityKey->toString();
        foreach ($timesheets as $index => $timesheet) {
            if ($timesheet->activity->entityKey->toString() !== $firstActivityKey) {
                $io->error(
                    sprintf(
                        'Timesheet %s has a different activity (%s) than the first timesheet (%s). ' .
                        'All timesheets must have the same activity to merge.',
                        $timesheet->uuid,
                        $timesheet->activity->name,
                        $timesheets[0]->activity->name
                    )
                );
                return Command::FAILURE;
            }
        }

        // Validate all timesheets have the same role
        $firstRoleId = $timesheets[0]->role?->id;
        foreach ($timesheets as $timesheet) {
            $roleId = $timesheet->role?->id;
            if ($roleId !== $firstRoleId) {
                $io->error(
                    sprintf(
                        'Timesheet %s has a different role (%s) than the first timesheet (%s). ' .
                        'All timesheets must have the same role to merge.',
                        $timesheet->uuid,
                        $roleId !== null ? (string) $roleId : 'null',
                        $firstRoleId !== null ? (string) $firstRoleId : 'null'
                    )
                );
                return Command::FAILURE;
            }
        }

        // Display merge preview
        $io->writeln('<info>Timesheets to merge:</info>');
        $totalTime = 0.0;
        $descriptions = [];
        $allFrameUuids = [];
        $hasZebraId = false;

        foreach ($timesheets as $timesheet) {
            $io->writeln(sprintf(
                '  UUID: %s | Date: %s | Time: %.2f hours | Description: %s',
                $timesheet->uuid,
                $timesheet->date->format('Y-m-d'),
                $timesheet->time,
                $timesheet->description
            ));
            $totalTime += $timesheet->time;
            $descriptions[] = $timesheet->description;
            $allFrameUuids = array_merge($allFrameUuids, $timesheet->frameUuids);
            if ($timesheet->zebraId !== null) {
                $hasZebraId = true;
            }
        }

        $io->writeln('');
        $io->writeln('<info>Merged timesheet will have:</info>');
        $io->writeln(sprintf('  Activity: %s', $timesheets[0]->activity->name));
        $io->writeln(sprintf('  Role: %s', $timesheets[0]->role !== null ? $timesheets[0]->role->name : 'Individual'));
        $io->writeln(sprintf('  Date: %s', $timesheets[0]->date->format('Y-m-d')));
        $io->writeln(sprintf('  Total Time: %.2f hours', $totalTime));
        $io->writeln(sprintf('  Combined Description: %s', implode(' | ', $descriptions)));

        if ($hasZebraId) {
            $io->warning('One or more timesheets are synced to Zebra. The merged timesheet will lose sync status.');
        }

        $io->writeln('');

        // Confirm merge
        if (!$force) {
            if (!$input->isInteractive()) {
                $io->error('Merge requires confirmation. Use --force flag for non-interactive mode.');
                return Command::FAILURE;
            }

            $confirmed = $io->confirm(
                'Are you sure you want to merge these timesheets?',
                false
            );

            if (!$confirmed) {
                $io->info('Merge cancelled.');
                return Command::SUCCESS;
            }
        }

        // Merge timesheets
        try {
            $firstTimesheet = $timesheets[0];
            $mergedDescription = implode(' | ', $descriptions);

            // Combine client descriptions if they exist
            $clientDescriptions = [];
            foreach ($timesheets as $timesheet) {
                if ($timesheet->clientDescription !== null && trim($timesheet->clientDescription) !== '') {
                    $clientDescriptions[] = $timesheet->clientDescription;
                }
            }
            $mergedClientDescription = !empty($clientDescriptions) ? implode(' | ', $clientDescriptions) : null;

            // Use the earliest updatedAt
            $earliestUpdatedAt = $firstTimesheet->updatedAt;
            foreach ($timesheets as $timesheet) {
                if ($timesheet->updatedAt->lt($earliestUpdatedAt)) {
                    $earliestUpdatedAt = $timesheet->updatedAt;
                }
            }

        // Validate total time is a multiple of 0.25
            $remainder = fmod($totalTime * 100, 25);
            if (abs($remainder) > 0.0001) {
                $io->error(
                    sprintf(
                        'Total time (%.2f hours) must be a multiple of 0.25. ' .
                        'The sum of the timesheets does not meet this requirement.',
                        $totalTime
                    )
                );
                return Command::FAILURE;
            }

            if ($totalTime <= 0) {
                $io->error('Total time must be positive');
                return Command::FAILURE;
            }

        // Remove duplicate frame UUIDs
            $uniqueFrameUuids = array_values(array_unique($allFrameUuids));

        // Create merged timesheet using the first UUID
            $mergedTimesheet = TimesheetFactory::create(
                $firstTimesheet->activity,
                $mergedDescription,
                $mergedClientDescription,
                $totalTime,
                $firstTimesheet->date,
                $firstTimesheet->role,
                $firstTimesheet->individualAction,
                $uniqueFrameUuids,
                null, // zebraId - set to null since we're merging
                $earliestUpdatedAt,
                Uuid::fromHex($firstTimesheet->uuid), // Use first UUID
                false // doNotSync
            );

            // Save the merged timesheet
            $this->timesheetRepository->save($mergedTimesheet);

            // Delete the other timesheets
            for ($i = 1; $i < count($timesheets); $i++) {
                $this->timesheetRepository->remove($timesheets[$i]->uuid);
            }

            $io->success(
                sprintf(
                    'Successfully merged %d timesheets into %s',
                    count($timesheets),
                    $firstTimesheet->uuid
                )
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to merge timesheets: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('timesheets')) {
            $this->timesheetAutocompletion->suggest($input, $suggestions);
        }
    }
}
