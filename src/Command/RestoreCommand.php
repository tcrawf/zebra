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
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\FileStorage\HomeDirectoryTrait;

class RestoreCommand extends Command
{
    use HomeDirectoryTrait;

    private const string FRAMES_FILENAME = 'frames.json';
    private const string TIMESHEETS_FILENAME = 'timesheets.json';
    private const string LOCAL_PROJECTS_FILENAME = 'local-projects.json';
    private const string BACKUPS_DIRECTORY = 'backups';

    protected function configure(): void
    {
        $this
            ->setName('restore')
            ->setDescription('Restore frames, timesheets, or local projects from a backup')
            ->addArgument(
                'backup',
                InputArgument::OPTIONAL,
                'Backup filename to restore (optional, will prompt if not provided)'
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Type of backup to restore: frames, timesheets, or local-projects',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Get and validate type option
            $type = $input->getOption('type');
            if ($type === null) {
                $io->error(
                    'The --type option is required. Use --type=frames, --type=timesheets, or --type=local-projects'
                );
                return Command::FAILURE;
            }

            if ($type !== 'frames' && $type !== 'timesheets' && $type !== 'local-projects') {
                $io->error(
                    sprintf(
                        'Invalid type "%s". Use --type=frames, --type=timesheets, or --type=local-projects',
                        $type
                    )
                );
                return Command::FAILURE;
            }

            $homeDir = $this->getHomeDirectory();
            $zebraDir = $homeDir . DIRECTORY_SEPARATOR . '.zebra';
            $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . self::BACKUPS_DIRECTORY;

            // Determine source and target files based on type
            if ($type === 'frames') {
                $sourceFilename = self::FRAMES_FILENAME;
            } elseif ($type === 'timesheets') {
                $sourceFilename = self::TIMESHEETS_FILENAME;
            } else {
                // $type === 'local-projects' (already validated above)
                $sourceFilename = self::LOCAL_PROJECTS_FILENAME;
            }
            $sourcePath = $zebraDir . DIRECTORY_SEPARATOR . $sourceFilename;

            // Check if backups directory exists
            if (!is_dir($backupsDir)) {
                $io->error('No backups directory found. No backups available to restore.');
                return Command::FAILURE;
            }

            // Get backup filename from argument or prompt
            $backupFilename = $input->getArgument('backup');

            if ($backupFilename === null) {
                // Interactive mode: list backups and let user choose
                $backup = $this->selectBackup($io, $input, $backupsDir, $type);
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

            // Validate backup filename matches type
            if ($type === 'frames') {
                $expectedPrefix = 'frames-';
            } elseif ($type === 'timesheets') {
                $expectedPrefix = 'timesheets-';
            } else {
                // $type === 'local-projects' (already validated above)
                $expectedPrefix = 'local-projects-';
            }
            if (!str_starts_with($backupFilename, $expectedPrefix)) {
                $io->error(sprintf('Backup filename "%s" does not match type "%s"', $backupFilename, $type));
                return Command::FAILURE;
            }

            // Validate backup file is readable JSON
            $backupContent = file_get_contents($backupPath);
            if ($backupContent === false) {
                $io->error(sprintf('Failed to read backup file: %s', $backupPath));
                return Command::FAILURE;
            }

            $backupData = json_decode($backupContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $io->error(sprintf('Invalid JSON in backup file: %s', $backupPath));
                return Command::FAILURE;
            }

            // Create backup of current file if it exists
            if (file_exists($sourcePath)) {
                $currentBackupPath = $this->createCurrentBackup($zebraDir, $sourcePath, $type);
                if ($currentBackupPath !== null) {
                    $io->info(sprintf('Current %s backed up to: %s', $sourceFilename, basename($currentBackupPath)));
                }
            }

            // Restore backup to target file
            if (!copy($backupPath, $sourcePath)) {
                $io->error(sprintf('Failed to restore backup: %s', $backupPath));
                return Command::FAILURE;
            }

            $io->success(sprintf('%s restored from backup: %s', ucfirst($type), $backupFilename));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Restore failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * Select a backup interactively from available backups.
     *
     * @param SymfonyStyle $io
     * @param InputInterface $input
     * @param string $backupsDir
     * @param string $type
     * @return string|null Backup filename or null if cancelled
     */
    private function selectBackup(SymfonyStyle $io, InputInterface $input, string $backupsDir, string $type): ?string
    {
        // Get backup files based on type
        $pattern = match ($type) {
            'frames' => 'frames-*.json',
            'timesheets' => 'timesheets-*.json',
            'local-projects' => 'local-projects-*.json',
            default => throw new \InvalidArgumentException("Invalid type: {$type}")
        };
        $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . $pattern);
        if (empty($backupFiles)) {
            $io->error(sprintf('No %s backup files found.', $type));
            return null;
        }

        // Sort backups by filename (newest first, since filename contains timestamp)
        rsort($backupFiles);

        // Build options for choice question
        $backupOptions = [];
        $backupMap = [];

        foreach ($backupFiles as $backupFile) {
            $filename = basename($backupFile);

            // Parse timestamp from filename:
            // frames-YYYY-MM-DD_HH-MM-SS-UTC.json, timesheets-YYYY-MM-DD_HH-MM-SS-UTC.json,
            // or local-projects-YYYY-MM-DD_HH-MM-SS-UTC.json
            $prefix = match ($type) {
                'frames' => 'frames-',
                'timesheets' => 'timesheets-',
                'local-projects' => 'local-projects-',
                default => throw new \InvalidArgumentException("Invalid type: {$type}")
            };
            $timestampPart = str_replace([$prefix, '-UTC.json'], '', $filename);
            $timestamp = Carbon::createFromFormat('Y-m-d_H-i-s', $timestampPart, 'UTC');

            if ($timestamp !== false) {
                $displayName = sprintf(
                    '%s (%s)',
                    $filename,
                    $timestamp->format('Y-m-d H:i:s') . ' UTC'
                );
            } else {
                $displayName = $filename;
            }

            $backupOptions[] = $displayName;
            $backupMap[$displayName] = $filename;
        }

        // Check if input is interactive
        if (!$input->isInteractive()) {
            $io->error('Backup selection requires interactive mode. Please provide backup filename as argument.');
            return null;
        }

        $prompt = sprintf('Please select a %s backup to restore:', $type);
        $question = new ChoiceQuestion($prompt, $backupOptions);
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
     * Create a backup of the current file before restoring.
     *
     * @param string $zebraDir
     * @param string $sourcePath
     * @param string $type
     * @return string|null Backup path or null if failed
     */
    private function createCurrentBackup(string $zebraDir, string $sourcePath, string $type): ?string
    {
        try {
            $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . self::BACKUPS_DIRECTORY;

            // Ensure backups directory exists
            if (!is_dir($backupsDir)) {
                if (!mkdir($backupsDir, 0755, true) && !is_dir($backupsDir)) {
                    return null;
                }
            }

            // Generate backup filename with UTC timestamp
            $timestamp = Carbon::now('UTC');
            $timestampString = $timestamp->format('Y-m-d_H-i-s') . '-UTC';
            if ($type === 'frames') {
                $prefix = 'frames-';
            } elseif ($type === 'timesheets') {
                $prefix = 'timesheets-';
            } else {
                // $type === 'local-projects' (already validated above)
                $prefix = 'local-projects-';
            }
            $backupFilename = sprintf('%s%s.json', $prefix, $timestampString);
            $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

            // Copy current file to backup location
            if (!copy($sourcePath, $backupPath)) {
                return null;
            }

            return $backupPath;
        } catch (\Exception $e) {
            return null;
        }
    }
}
