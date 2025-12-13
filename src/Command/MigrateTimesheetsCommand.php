<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFileStorageFactory;
use Tcrawf\Zebra\Timesheet\TimesheetMigrationService;

/**
 * Command to migrate timesheets from old format (denormalized activity data)
 * to new format (normalized activity key only).
 */
class MigrateTimesheetsCommand extends Command
{
    /**
     * @param ConfigFileStorageInterface $configStorage
     */
    public function __construct(
        private readonly ConfigFileStorageInterface $configStorage
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('migrate-timesheets')
            ->setDescription('Migrate timesheets from old format to new normalized format');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $storageFactory = new TimesheetFileStorageFactory();
            $migrationService = new TimesheetMigrationService($storageFactory);

            // Check if migration is needed
            if (!$migrationService->needsMigration()) {
                $io->success('No migration needed. All timesheets are already in the new format.');
                return Command::SUCCESS;
            }

            // Prompt for confirmation if interactive
            if ($input->isInteractive()) {
                $question = new ConfirmationQuestion(
                    'Timesheets need to be migrated to the new format. ' .
                    'This will rewrite timesheet storage file. Continue? (yes/no) ',
                    true
                );
                if (!$io->askQuestion($question)) {
                    $io->info('Migration cancelled.');
                    return Command::SUCCESS;
                }
            }

            // Perform migration
            $io->info('Migrating timesheets...');
            $migratedCount = $migrationService->migrateTimesheets();

            if ($migratedCount > 0) {
                // Set config flag to indicate migration is complete
                $this->configStorage->set('timesheets.migrated', true);
                $io->success(sprintf('Successfully migrated %d timesheet(s) to new format.', $migratedCount));
            } else {
                $io->info('No timesheets were migrated.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Migration failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
