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
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetInterface;
use Tcrawf\Zebra\Timesheet\TimesheetDateHelper;
use Tcrawf\Zebra\Timesheet\TimesheetSyncServiceInterface;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;

class TimesheetPushCommand extends Command
{
    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $localRepository,
        private readonly ZebraTimesheetRepositoryInterface $zebraRepository,
        private readonly TimesheetSyncServiceInterface $syncService,
        private readonly TimesheetAutocompletion $timesheetAutocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('timesheet:push')
            ->setDescription('Push local timesheets to Zebra')
            ->addArgument(
                'uuid',
                InputArgument::OPTIONAL,
                'UUID of a specific timesheet to push (optional, if not provided pushes all for date)'
            )
            ->addOption(
                'date',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Date for the timesheets (YYYY-MM-DD format, defaults to today, ignored if UUID is provided)',
                null
            )
            ->addOption(
                'yesterday',
                null,
                InputOption::VALUE_NONE,
                'Push timesheets for yesterday (ignored if UUID is provided)'
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

        // Check if UUID is provided
        $uuid = $input->getArgument('uuid');
        if ($uuid !== null) {
            // Push specific timesheet by UUID
            $timesheet = $this->resolveTimesheet($uuid);
            if ($timesheet === null) {
                // Check if it was ambiguous (multiple matches)
                if (preg_match('/^[0-9a-f]+$/i', $uuid)) {
                    $allTimesheets = $this->localRepository->all();
                    $matches = [];
                    foreach ($allTimesheets as $t) {
                        if (stripos($t->uuid, $uuid) === 0) {
                            $matches[] = $t;
                        }
                    }
                    if (count($matches) > 1) {
                        $io->error(sprintf(
                            "Ambiguous UUID '{$uuid}': matches %d timesheets. Please use a longer prefix.",
                            count($matches)
                        ));
                        return Command::FAILURE;
                    }
                }
                $io->error("Timesheet '{$uuid}' not found");
                return Command::FAILURE;
            }

            $localTimesheets = [$timesheet];
        } else {
            // Parse date using centralized helper
            try {
                $date = TimesheetDateHelper::parseDateInput($input);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }

            // Get local timesheets for the date
            $localTimesheets = $this->localRepository->getByDateRange($date, $date);

            if (empty($localTimesheets)) {
                $dateStr = TimesheetDateHelper::formatDateForStorage($date);
                $io->info("No local timesheets found for {$dateStr}");
                return Command::SUCCESS;
            }

            // Filter out doNotSync timesheets for summary and processing
            $syncableTimesheets = array_values(array_filter(
                $localTimesheets,
                static fn(TimesheetInterface $t): bool => !$t->doNotSync
            ));

            // If no syncable timesheets, show message and exit
            if (empty($syncableTimesheets)) {
                $dateStr = TimesheetDateHelper::formatDateForStorage($date);
                $io->info("No syncable timesheets found for {$dateStr} (all are flagged as doNotSync)");
                return Command::SUCCESS;
            }

            // Show summary and prompt for confirmation mode (unless force flag is set)
            if (!$force) {
                if (!$input->isInteractive()) {
                    $io->error(
                        'Pushing multiple timesheets requires confirmation. ' .
                        'Use --force flag for non-interactive mode.'
                    );
                    return Command::FAILURE;
                }

                $this->displayTimesheetSummary($io, $syncableTimesheets);
                $confirmationMode = $this->promptForConfirmationMode($io);

                if ($confirmationMode === 'abort') {
                    $io->info('Push cancelled.');
                    return Command::SUCCESS;
                }

                // If "confirm all", set force to true for this batch
                if ($confirmationMode === 'confirm_all') {
                    $force = true;
                }
                // If "confirm each", keep force as false to proceed with individual confirmations
            }

            // Use filtered list for processing
            $localTimesheets = $syncableTimesheets;
        }

        $pushedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($localTimesheets as $localTimesheet) {
            try {
                // Skip timesheets flagged as doNotSync
                if ($localTimesheet->doNotSync) {
                    $skippedCount++;
                    $io->writeln(sprintf(
                        '<comment>Skipped timesheet %s: flagged as doNotSync</comment>',
                        $localTimesheet->uuid
                    ));
                    continue;
                }
                if ($localTimesheet->zebraId === null) {
                    // New timesheet - confirm before creating
                    if (!$force) {
                        if (!$input->isInteractive()) {
                            $io->error(
                                'Creating new timesheets requires confirmation. ' .
                                'Use --force flag for non-interactive mode.'
                            );
                            return Command::FAILURE;
                        }

                        $confirmed = $io->confirm(
                            sprintf(
                                'Create new timesheet in Zebra? Activity: %s, Time: %.2f hours, Date: %s',
                                $localTimesheet->activity->name,
                                $localTimesheet->time,
                                $localTimesheet->date->format('Y-m-d')
                            ),
                            false
                        );

                        if (!$confirmed) {
                            $skippedCount++;
                            continue;
                        }
                    }

                    // Push new timesheet
                    $result = $this->syncService->pushLocalToZebra($localTimesheet);
                    if ($result !== null) {
                        $pushedCount++;
                        $io->writeln(sprintf(
                            '<info>Created timesheet in Zebra (ID: %d)</info>',
                            $result->zebraId ?? 0
                        ));
                    } else {
                        $skippedCount++;
                    }
                } else {
                    // Existing timesheet - check if remote is newer
                    $remoteTimesheet = $this->zebraRepository->getByZebraId($localTimesheet->zebraId);
                    $remoteIsNewer = false;

                    if ($remoteTimesheet !== null) {
                        $localUpdatedAt = $localTimesheet->updatedAt->timestamp;
                        $remoteUpdatedAt = $remoteTimesheet->updatedAt->timestamp;

                        if ($remoteUpdatedAt > $localUpdatedAt) {
                            $remoteIsNewer = true;
                            $io->warning(sprintf(
                                'Remote timesheet (ID: %d) is newer than local version. ' .
                                'Local updated: %s, Remote updated: %s',
                                $localTimesheet->zebraId,
                                $localTimesheet->updatedAt->format('Y-m-d H:i:s'),
                                $remoteTimesheet->updatedAt->format('Y-m-d H:i:s')
                            ));
                        }
                    }

                    // Prompt for confirmation
                    if (!$force) {
                        if (!$input->isInteractive()) {
                            $io->error(
                                'Updating timesheets requires confirmation. ' .
                                'Use --force flag for non-interactive mode.'
                            );
                            return Command::FAILURE;
                        }

                        $message = sprintf(
                            'Update timesheet in Zebra? ID: %d, Activity: %s, Time: %.2f hours, Date: %s',
                            $localTimesheet->zebraId,
                            $localTimesheet->activity->name,
                            $localTimesheet->time,
                            $localTimesheet->date->format('Y-m-d')
                        );

                        if ($remoteIsNewer) {
                            $message .= ' (WARNING: Remote version is newer!)';
                        }

                        $confirmed = $io->confirm($message, false);

                        if (!$confirmed) {
                            $skippedCount++;
                            continue;
                        }
                    }

                    // Push update
                    $result = $this->syncService->pushLocalToZebra(
                        $localTimesheet,
                        function (TimesheetInterface $timesheet): bool {
                            // This callback is called by the repository's update method
                            // We've already confirmed above, so just return true
                            return true;
                        }
                    );

                    if ($result !== null) {
                        $pushedCount++;
                        $io->writeln(sprintf(
                            '<info>Updated timesheet in Zebra (ID: %d)</info>',
                            $result->zebraId ?? 0
                        ));
                    } else {
                        $skippedCount++;
                    }
                }
            } catch (\Exception $e) {
                $errorCount++;
                $io->error(sprintf(
                    'Failed to push timesheet %s: %s',
                    $localTimesheet->uuid,
                    $e->getMessage()
                ));
            }
        }

        // Summary
        $io->writeln('');
        if ($pushedCount > 0) {
            $io->success(sprintf('Pushed %d timesheet(s)', $pushedCount));
        }
        if ($skippedCount > 0) {
            $io->info(sprintf('Skipped %d timesheet(s)', $skippedCount));
        }
        if ($errorCount > 0) {
            $io->error(sprintf('Failed to push %d timesheet(s)', $errorCount));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Display a summary table of timesheets to be pushed.
     *
     * @param SymfonyStyle $io
     * @param array<TimesheetInterface> $timesheets
     * @return void
     */
    private function displayTimesheetSummary(SymfonyStyle $io, array $timesheets): void
    {
        $io->writeln('');
        $io->section('Timesheets to be pushed:');

        $rows = [];
        foreach ($timesheets as $index => $timesheet) {
            $status = $timesheet->zebraId === null ? 'New' : 'Update';
            $rows[] = [
                $index + 1,
                substr($timesheet->uuid, 0, 8) . '...',
                $timesheet->activity->name,
                sprintf('%.2f', $timesheet->time),
                $timesheet->date->format('Y-m-d'),
                $status,
                $timesheet->zebraId ?? '-',
            ];
        }

        $io->table(
            ['#', 'UUID', 'Activity', 'Hours', 'Date', 'Status', 'Zebra ID'],
            $rows
        );
    }

    /**
     * Prompt user to select confirmation mode.
     *
     * @param SymfonyStyle $io
     * @return string One of: 'confirm_all', 'confirm_each', 'abort'
     */
    private function promptForConfirmationMode(SymfonyStyle $io): string
    {
        $options = [
            'confirm_all' => 'Confirm all',
            'confirm_each' => 'Confirm each',
            'abort' => 'Abort',
        ];

        $question = new ChoiceQuestion(
            'How would you like to proceed?',
            array_values($options),
            0
        );
        $question->setErrorMessage('Invalid choice: %s');

        $selected = $io->askQuestion($question);

        // Find the key for the selected option
        foreach ($options as $key => $value) {
            if ($value === $selected) {
                return $key;
            }
        }

        return 'abort'; // Default to abort if something goes wrong
    }

    /**
     * Resolve timesheet identifier to a timesheet.
     * Supports full UUID, partial UUID (prefix match), or negative index (e.g., -1 for last timesheet).
     *
     * @param string $identifier UUID, partial UUID, or index
     * @return TimesheetInterface|null
     */
    private function resolveTimesheet(string $identifier): ?TimesheetInterface
    {
        // Check if it's a full UUID
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            return $this->localRepository->get($identifier);
        }

        // Check if it's a partial UUID (hexadecimal string)
        if (preg_match('/^[0-9a-f]+$/i', $identifier)) {
            $allTimesheets = $this->localRepository->all();
            $matches = [];

            foreach ($allTimesheets as $timesheet) {
                // Check if UUID starts with the provided prefix (case-insensitive)
                if (stripos($timesheet->uuid, $identifier) === 0) {
                    $matches[] = $timesheet;
                }
            }

            // If exactly one match, return it
            if (count($matches) === 1) {
                return $matches[0];
            }

            // If multiple matches, return null (ambiguous)
            if (count($matches) > 1) {
                return null;
            }
        }

        // Try as negative index (-1 for last, -2 for second-to-last)
        if (preg_match('/^-?\d+$/', $identifier)) {
            $index = (int) $identifier;
            $allTimesheets = $this->localRepository->all();

            // Sort by date (descending) and then by time (descending) - most recent first
            usort($allTimesheets, static function ($a, $b) {
                $dateCompare = $b->date->timestamp <=> $a->date->timestamp;
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }
                return $b->time <=> $a->time;
            });

            // Convert negative index to positive array index
            if ($index < 0) {
                $arrayIndex = abs($index) - 1;
            } else {
                $arrayIndex = $index - 1;
            }

            if ($arrayIndex >= 0 && $arrayIndex < count($allTimesheets)) {
                return $allTimesheets[$arrayIndex];
            }
        }

        return null;
    }


    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('uuid')) {
            $this->timesheetAutocompletion->suggest($input, $suggestions);
        }
    }
}
