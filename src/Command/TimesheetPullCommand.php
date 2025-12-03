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
use Tcrawf\Zebra\Timesheet\TimesheetDateHelper;
use Tcrawf\Zebra\Timesheet\TimesheetInterface;
use Tcrawf\Zebra\Timesheet\TimesheetSyncServiceInterface;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;

class TimesheetPullCommand extends Command
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
            ->setName('timesheet:pull')
            ->setDescription('Pull timesheets from Zebra to local storage')
            ->addArgument(
                'uuid',
                InputArgument::OPTIONAL,
                'UUID of a specific timesheet to pull (optional, if not provided pulls all for date)'
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
                'Pull timesheets for yesterday (ignored if UUID is provided)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip warnings and overwrite local changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force') === true;

        // Check if UUID is provided
        $uuid = $input->getArgument('uuid');
        if ($uuid !== null) {
            // Pull specific timesheet by UUID
            $localTimesheet = $this->resolveTimesheet($uuid);
            if ($localTimesheet === null) {
                $io->error("Timesheet '{$uuid}' not found locally");
                return Command::FAILURE;
            }

            if ($localTimesheet->zebraId === null) {
                $io->error("Timesheet '{$uuid}' is not synced to Zebra (no zebraId). Cannot pull.");
                return Command::FAILURE;
            }

            // Fetch remote timesheet
            $remoteTimesheet = $this->zebraRepository->getByZebraId($localTimesheet->zebraId);

            if ($remoteTimesheet === null) {
                // Timesheet not found (404) - handle deletion
                $io->warning(sprintf(
                    'Timesheet with Zebra ID %d was deleted on the remote server.',
                    $localTimesheet->zebraId
                ));
                $io->writeln('');

                if (!$input->isInteractive()) {
                    $io->note('Local timesheet will be kept. Use --force to delete it automatically.');
                    return Command::SUCCESS;
                }

                $deleteLocal = $io->confirm(
                    'Do you want to delete the local timesheet as well?',
                    false
                );

                if ($deleteLocal) {
                    try {
                        $this->localRepository->remove($localTimesheet->uuid);
                        $io->success('Local timesheet deleted.');
                        return Command::SUCCESS;
                    } catch (\Exception $deleteException) {
                        $io->error(sprintf(
                            'Failed to delete local timesheet: %s',
                            $deleteException->getMessage()
                        ));
                        return Command::FAILURE;
                    }
                } else {
                    $io->info('Local timesheet kept.');
                    return Command::SUCCESS;
                }
            }

            // Check if local is newer
            $localUpdatedAt = $localTimesheet->updatedAt->timestamp;
            $remoteUpdatedAt = $remoteTimesheet->updatedAt->timestamp;

            if ($localUpdatedAt > $remoteUpdatedAt && !$force) {
                $io->warning(sprintf(
                    'Local timesheet (ID: %d) was modified after remote version. ' .
                    'Local updated: %s, Remote updated: %s. ' .
                    'Local changes will be overwritten. Use --force to suppress this warning.',
                    $localTimesheet->zebraId,
                    $localTimesheet->updatedAt->format('Y-m-d H:i:s'),
                    $remoteTimesheet->updatedAt->format('Y-m-d H:i:s')
                ));
            }

            // Pull the single timesheet
            try {
                // Use sync service to pull, but we need to pull by date range
                // Since we have the remote timesheet, we can pull by its date
                $pulledTimesheets = $this->syncService->pullFromZebra(
                    $remoteTimesheet->date,
                    $remoteTimesheet->date
                );

                // Filter to only the one we want (by zebraId)
                $pulledTimesheets = array_filter(
                    $pulledTimesheets,
                    static fn($t) => $t->zebraId === $localTimesheet->zebraId
                );
                $pulledCount = count($pulledTimesheets);
            } catch (\Exception $e) {
                $io->error(sprintf('Failed to pull timesheet: %s', $e->getMessage()));
                return Command::FAILURE;
            }

            // Summary
            $io->writeln('');
            if ($pulledCount > 0) {
                $io->success(sprintf('Pulled timesheet (Zebra ID: %d)', $localTimesheet->zebraId));
            } else {
                $io->info('Timesheet is already up to date');
            }

            return Command::SUCCESS;
        }

        // Parse date using centralized helper (for date-based pull)
        try {
            $date = TimesheetDateHelper::parseDateInput($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Get remote timesheets for the date
        $remoteTimesheets = $this->zebraRepository->getByDateRange($date, $date);

        if (empty($remoteTimesheets)) {
            $dateStr = TimesheetDateHelper::formatDateForStorage($date);
            $io->info("No remote timesheets found for {$dateStr}");
            return Command::SUCCESS;
        }

        // Show summary and prompt for confirmation mode (unless force flag is set)
        if (!$force) {
            if (!$input->isInteractive()) {
                $io->error(
                    'Pulling multiple timesheets requires confirmation. ' .
                    'Use --force flag for non-interactive mode.'
                );
                return Command::FAILURE;
            }

            $this->displayTimesheetSummary($io, $remoteTimesheets);
            $confirmationMode = $this->promptForConfirmationMode($io);

            if ($confirmationMode === 'abort') {
                $io->info('Pull cancelled.');
                return Command::SUCCESS;
            }

            // If "confirm all", set force to true for this batch (suppress warnings)
            if ($confirmationMode === 'confirm_all') {
                $force = true;
            }
            // If "confirm each", keep force as false to show warnings
        }

        $warningsShown = false;

        // Check for local timesheets that are newer than remote before pulling
        foreach ($remoteTimesheets as $remoteTimesheet) {
            if ($remoteTimesheet->zebraId === null) {
                continue;
            }

            $localTimesheet = $this->localRepository->getByZebraId($remoteTimesheet->zebraId);

            if ($localTimesheet !== null) {
                $localUpdatedAt = $localTimesheet->updatedAt->timestamp;
                $remoteUpdatedAt = $remoteTimesheet->updatedAt->timestamp;

                if ($localUpdatedAt > $remoteUpdatedAt) {
                    // Local is newer - warn user
                    if (!$force) {
                        $warningsShown = true;
                        $io->warning(sprintf(
                            'Local timesheet (ID: %d) was modified after remote version. ' .
                            'Local updated: %s, Remote updated: %s. ' .
                            'Local changes will be overwritten. Use --force to suppress this warning.',
                            $remoteTimesheet->zebraId,
                            $localTimesheet->updatedAt->format('Y-m-d H:i:s'),
                            $remoteTimesheet->updatedAt->format('Y-m-d H:i:s')
                        ));
                    }
                }
            }
        }

        // Pull all timesheets for the date
        try {
            $pulledTimesheets = $this->syncService->pullFromZebra($date, $date);
            $pulledCount = count($pulledTimesheets);
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to pull timesheets: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // Summary
        $io->writeln('');
        if ($pulledCount > 0) {
            $io->success(sprintf('Pulled %d timesheet(s)', $pulledCount));
        } else {
            $io->info('No timesheets were pulled (all are already up to date)');
        }
        if ($warningsShown && !$force) {
            $io->note('Some local timesheets were overwritten. Review the warnings above.');
        }

        return Command::SUCCESS;
    }

    /**
     * Display a summary table of timesheets to be pulled.
     *
     * @param SymfonyStyle $io
     * @param array<TimesheetInterface> $timesheets
     * @return void
     */
    private function displayTimesheetSummary(SymfonyStyle $io, array $timesheets): void
    {
        $io->writeln('');
        $io->section('Timesheets to be pulled:');

        $rows = [];
        foreach ($timesheets as $index => $timesheet) {
            // Check if local timesheet exists and is newer
            $localTimesheet = $timesheet->zebraId !== null
                ? $this->localRepository->getByZebraId($timesheet->zebraId)
                : null;

            $status = 'New';
            $warning = '';
            if ($localTimesheet !== null) {
                $status = 'Update';
                $localUpdatedAt = $localTimesheet->updatedAt->timestamp;
                $remoteUpdatedAt = $timesheet->updatedAt->timestamp;
                if ($localUpdatedAt > $remoteUpdatedAt) {
                    $warning = '⚠ Local newer';
                }
            }

            $rows[] = [
                $index + 1,
                $timesheet->zebraId ?? '-',
                $timesheet->activity->name,
                sprintf('%.2f', $timesheet->time),
                $timesheet->date->format('Y-m-d'),
                $status,
                $warning,
            ];
        }

        $io->table(
            ['#', 'Zebra ID', 'Activity', 'Hours', 'Date', 'Status', 'Warning'],
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
