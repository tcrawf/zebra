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
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;

class TimesheetDeleteCommand extends Command
{
    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $localRepository,
        private readonly ZebraTimesheetRepositoryInterface $zebraRepository,
        private readonly TimesheetAutocompletion $timesheetAutocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('timesheet:delete')
            ->setDescription('Delete a timesheet entry')
            ->addArgument(
                'timesheet',
                InputArgument::REQUIRED,
                'Timesheet UUID'
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

        $timesheetIdentifier = $input->getArgument('timesheet');
        $timesheet = $this->localRepository->get($timesheetIdentifier);

        if ($timesheet === null) {
            $io->error("Timesheet '{$timesheetIdentifier}' not found");
            return Command::FAILURE;
        }

        // Display timesheet information
        $io->writeln('<info>Timesheet to delete:</info>');
        $io->writeln(sprintf('  UUID: %s', $timesheet->uuid));
        $io->writeln(sprintf('  Date: %s', $timesheet->date->format('Y-m-d')));
        $io->writeln(sprintf('  Activity: %s', $timesheet->activity->name));
        $io->writeln(sprintf('  Time: %.2f hours', $timesheet->time));
        $io->writeln(sprintf('  Description: %s', $timesheet->description));
        if ($timesheet->zebraId !== null) {
            $io->writeln(sprintf('  Zebra ID: %d', $timesheet->zebraId));
        }
        $io->writeln('');

        // Confirm local deletion
        if (!$force) {
            if (!$input->isInteractive()) {
                $io->error('Deletion requires confirmation. Use --force flag for non-interactive mode.');
                return Command::FAILURE;
            }

            $confirmed = $io->confirm(
                'Are you sure you want to delete this timesheet locally?',
                false
            );

            if (!$confirmed) {
                $io->info('Deletion cancelled.');
                return Command::SUCCESS;
            }
        }

        // If timesheet has a zebraId, ask if it should also be deleted from Zebra
        $deleteRemote = false;
        if ($timesheet->zebraId !== null) {
            if (!$force) {
                $deleteRemote = $io->confirm(
                    sprintf(
                        'This timesheet is synced to Zebra (ID: %d). ' .
                        'Do you also want to delete it from Zebra?',
                        $timesheet->zebraId
                    ),
                    false
                );
            } else {
                // In force mode, don't delete remote by default (safer)
                $deleteRemote = false;
            }
        }

        // Delete from Zebra first (if requested)
        $zebraDeleteSuccess = false;
        if ($deleteRemote && $timesheet->zebraId !== null) {
            try {
                $deleted = $this->zebraRepository->delete(
                    $timesheet->zebraId,
                    function (int $zebraId): bool {
                        // This callback is called by the repository's delete method
                        // We've already confirmed above, so just return true
                        return true;
                    }
                );

                if ($deleted) {
                    $io->success(sprintf('Timesheet deleted from Zebra (ID: %d)', $timesheet->zebraId));
                    $zebraDeleteSuccess = true;
                } else {
                    $io->warning('Failed to delete timesheet from Zebra (cancelled)');
                }
            } catch (\Throwable $e) {
                // Catch any exception or error from Zebra deletion
                // Don't let remote deletion failure prevent local deletion
                // Suppress the exception to prevent Symfony from displaying it
                $io->warning(
                    sprintf(
                        'Failed to delete timesheet from Zebra: %s. ' .
                        'Proceeding with local deletion anyway.',
                        $e->getMessage()
                    )
                );
                // Explicitly set zebraDeleteSuccess to false since deletion failed
                $zebraDeleteSuccess = false;
            }
        }

        // Delete locally
        try {
            $this->localRepository->remove($timesheet->uuid);
            $io->success('Timesheet deleted locally');

            if ($timesheet->zebraId !== null && !$deleteRemote) {
                $io->note('Timesheet was not deleted from Zebra. It will be re-synced on next pull.');
            } elseif ($timesheet->zebraId !== null && $deleteRemote && !$zebraDeleteSuccess) {
                $io->note('Local timesheet deleted, but Zebra deletion failed or was skipped.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to delete timesheet locally: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('timesheet')) {
            $this->timesheetAutocompletion->suggest($input, $suggestions);
        }
    }
}
