<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Frame\FrameFileStorageFactory;
use Tcrawf\Zebra\Frame\FrameMigrationService;

/**
 * Command to migrate frames from old format (denormalized activity data) to new format (normalized activity key only).
 */
class MigrateFramesCommand extends Command
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
            ->setName('migrate-frames')
            ->setDescription('Migrate frames from old format to new normalized format');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $storageFactory = new FrameFileStorageFactory();
            $migrationService = new FrameMigrationService($storageFactory);

            // Check if migration is needed
            if (!$migrationService->needsMigration()) {
                $io->success('No migration needed. All frames are already in the new format.');
                return Command::SUCCESS;
            }

            // Prompt for confirmation if interactive
            if ($input->isInteractive()) {
                $question = new ConfirmationQuestion(
                    'Frames need to be migrated to the new format. This will rewrite frame storage files. Continue? (yes/no) ',
                    true
                );
                if (!$io->askQuestion($question)) {
                    $io->info('Migration cancelled.');
                    return Command::SUCCESS;
                }
            }

            // Perform migration
            $io->info('Migrating frames...');
            $migratedCount = $migrationService->migrateFrames();

            if ($migratedCount > 0) {
                // Set config flag to indicate migration is complete
                $this->configStorage->set('frames.migrated', true);
                $io->success(sprintf('Successfully migrated %d frame(s) to new format.', $migratedCount));
            } else {
                $io->info('No frames were migrated.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Migration failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
