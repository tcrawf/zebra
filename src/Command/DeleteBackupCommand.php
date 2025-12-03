<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\FileStorage\HomeDirectoryTrait;

class DeleteBackupCommand extends Command
{
    use HomeDirectoryTrait;

    private const string BACKUPS_DIRECTORY = 'backups';

    protected function configure(): void
    {
        $this
            ->setName('delete-backup')
            ->setDescription('Delete a backup file')
            ->addArgument(
                'backup',
                InputArgument::OPTIONAL,
                'Backup filename to delete (optional, will prompt if not provided)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip confirmation prompt'
            )
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_OPTIONAL,
                'Delete backups older than specified number of days (default: 30 if option provided without value)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $homeDir = $this->getHomeDirectory();
            $zebraDir = $homeDir . DIRECTORY_SEPARATOR . '.zebra';
            $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . self::BACKUPS_DIRECTORY;

            // Check if backups directory exists
            if (!is_dir($backupsDir)) {
                $io->error('No backups directory found. No backups available to delete.');
                return Command::FAILURE;
            }

            // Check if --older-than option is used
            if ($input->hasParameterOption(['--older-than'], true)) {
                // Delete backups older than specified days
                $olderThanDays = $input->getOption('older-than');
                // If option provided without value, default to 30 days
                $days = $olderThanDays === null ? 30 : (int) $olderThanDays;
                return $this->deleteOldBackups($io, $input, $backupsDir, $days);
            }

            // Single backup deletion mode
            // Get backup filename from argument or prompt
            $backupFilename = $input->getArgument('backup');

            if ($backupFilename === null) {
                // Interactive mode: list backups and let user choose
                $backup = $this->selectBackup($io, $input, $backupsDir);
                if ($backup === null) {
                    return Command::FAILURE;
                }
                $backupFilename = $backup;
            }

            // Validate backup file exists
            $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;
            if (!file_exists($backupPath)) {
                $io->error(sprintf('Backup file not found: %s', $backupPath));
                return Command::FAILURE;
            }

            // Confirm deletion unless --force flag is set
            if (!$input->getOption('force')) {
                if (!$input->isInteractive()) {
                    $io->error('Deletion requires confirmation. Use --force flag for non-interactive mode.');
                    return Command::FAILURE;
                }

                $question = new ConfirmationQuestion(
                    sprintf('Are you sure you want to delete backup "%s"? (y/N): ', $backupFilename),
                    false
                );

                if (!$io->askQuestion($question)) {
                    $io->info('Deletion cancelled.');
                    return Command::SUCCESS;
                }
            }

            // Delete the backup file
            if (!unlink($backupPath)) {
                $io->error(sprintf('Failed to delete backup: %s', $backupPath));
                return Command::FAILURE;
            }

            $io->success(sprintf('Backup deleted: %s', $backupFilename));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Delete failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * Select a backup interactively from available backups.
     *
     * @param SymfonyStyle $io
     * @param InputInterface $input
     * @param string $backupsDir
     * @return string|null Backup filename or null if cancelled
     */
    private function selectBackup(SymfonyStyle $io, InputInterface $input, string $backupsDir): ?string
    {
        // Get all backup files (both frames and timesheets)
        $framesBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $timesheetsBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'timesheets-*.json');
        $backupFiles = array_merge($framesBackups ?: [], $timesheetsBackups ?: []);

        if (empty($backupFiles)) {
            $io->error('No backup files found.');
            return null;
        }

        // Sort backups by filename (newest first, since filename contains timestamp)
        rsort($backupFiles);

        // Build options for choice question
        $backupOptions = [];
        $backupMap = [];

        foreach ($backupFiles as $backupFile) {
            $filename = basename($backupFile);

            // Determine type and parse timestamp
            $type = str_starts_with($filename, 'frames-') ? 'frames' : 'timesheets';
            $prefix = $type === 'frames' ? 'frames-' : 'timesheets-';
            $timestampPart = str_replace([$prefix, '-UTC.json'], '', $filename);

            try {
                $timestamp = Carbon::createFromFormat('Y-m-d_H-i-s', $timestampPart, 'UTC');

                if ($timestamp !== false) {
                    $displayName = sprintf(
                        '[%s] %s (%s)',
                        $type,
                        $filename,
                        $timestamp->format('Y-m-d H:i:s') . ' UTC'
                    );
                } else {
                    $displayName = sprintf('[%s] %s', $type, $filename);
                }
            } catch (\Exception $e) {
                // If parsing fails, just use the filename with type indicator
                $displayName = sprintf('[%s] %s', $type, $filename);
            }

            $backupOptions[] = $displayName;
            $backupMap[$displayName] = $filename;
        }

        // Check if input is interactive
        if (!$input->isInteractive()) {
            $io->error('Backup selection requires interactive mode. Please provide backup filename as argument.');
            return null;
        }

        $question = new ChoiceQuestion(
            'Please select a backup to delete:',
            $backupOptions
        );
        $question->setErrorMessage('Invalid backup selection: %s');

        try {
            $selectedOption = $io->askQuestion($question);
            return $backupMap[$selectedOption] ?? null;
        } catch (\Exception $e) {
            $io->error('Failed to read input: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete backups older than specified number of days.
     *
     * @param SymfonyStyle $io
     * @param InputInterface $input
     * @param string $backupsDir
     * @param int $days
     * @return int
     */
    private function deleteOldBackups(SymfonyStyle $io, InputInterface $input, string $backupsDir, int $days): int
    {
        // Get cutoff date (days ago in UTC)
        $cutoffDate = Carbon::now('UTC')->subDays($days);

        // Get all backup files (both frames and timesheets)
        $framesBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $timesheetsBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'timesheets-*.json');
        $backupFiles = array_merge($framesBackups ?: [], $timesheetsBackups ?: []);

        if (empty($backupFiles)) {
            $io->info('No backup files found.');
            return Command::SUCCESS;
        }

        // Find backups older than cutoff date
        $oldBackups = [];
        foreach ($backupFiles as $backupFile) {
            $filename = basename($backupFile);

            // Determine type and parse timestamp
            $type = str_starts_with($filename, 'frames-') ? 'frames' : 'timesheets';
            $prefix = $type === 'frames' ? 'frames-' : 'timesheets-';
            $timestampPart = str_replace([$prefix, '-UTC.json'], '', $filename);

            try {
                $timestamp = Carbon::createFromFormat('Y-m-d_H-i-s', $timestampPart, 'UTC');

                if ($timestamp !== false && $timestamp->lt($cutoffDate)) {
                    $oldBackups[] = [
                        'path' => $backupFile,
                        'filename' => $filename,
                        'type' => $type,
                        'timestamp' => $timestamp,
                    ];
                }
            } catch (\Exception $e) {
                // Skip files that don't match the expected format
                // This allows the command to work even if there are manually created
                // backup files with different naming conventions
                continue;
            }
        }

        if (empty($oldBackups)) {
            $io->info(sprintf('No backups found older than %d days.', $days));
            return Command::SUCCESS;
        }

        // Sort by timestamp (oldest first)
        usort($oldBackups, static fn($a, $b) => $a['timestamp']->timestamp <=> $b['timestamp']->timestamp);

        // Display backups that will be deleted
        $io->writeln(sprintf('Found %d backup(s) older than %d days:', count($oldBackups), $days));
        foreach ($oldBackups as $backup) {
            $io->writeln(sprintf(
                '  - [%s] %s (%s UTC)',
                $backup['type'],
                $backup['filename'],
                $backup['timestamp']->format('Y-m-d H:i:s')
            ));
        }

        // Confirm deletion unless --force flag is set
        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Deletion requires confirmation. Use --force flag for non-interactive mode.');
                return Command::FAILURE;
            }

            $question = new ConfirmationQuestion(
                sprintf('Are you sure you want to delete %d backup(s)? (y/N): ', count($oldBackups)),
                false
            );

            if (!$io->askQuestion($question)) {
                $io->info('Deletion cancelled.');
                return Command::SUCCESS;
            }
        }

        // Delete old backups
        $deletedCount = 0;
        $failedCount = 0;

        foreach ($oldBackups as $backup) {
            if (unlink($backup['path'])) {
                $deletedCount++;
            } else {
                $failedCount++;
                $io->warning(sprintf('Failed to delete backup: %s', $backup['filename']));
            }
        }

        if ($failedCount > 0) {
            $io->error(sprintf('Deleted %d backup(s), failed to delete %d backup(s).', $deletedCount, $failedCount));
            return Command::FAILURE;
        }

        $io->success(sprintf('Deleted %d backup(s) older than %d days.', $deletedCount, $days));
        return Command::SUCCESS;
    }
}
