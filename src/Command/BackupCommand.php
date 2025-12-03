<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\FileStorage\HomeDirectoryTrait;

class BackupCommand extends Command
{
    use HomeDirectoryTrait;

    private const string FRAMES_FILENAME = 'frames.json';
    private const string TIMESHEETS_FILENAME = 'timesheets.json';
    private const string LOCAL_PROJECTS_FILENAME = 'local-projects.json';
    private const string BACKUPS_DIRECTORY = 'backups';
    private const string MARKER_FILENAME = '.last_backup_date';

    protected function configure(): void
    {
        $this
            ->setName('backup')
            ->setDescription('Backup frames, timesheets, and local projects to the backups sub-directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $homeDir = $this->getHomeDirectory();
            $zebraDir = $homeDir . DIRECTORY_SEPARATOR . '.zebra';
            $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . self::BACKUPS_DIRECTORY;

            // Create backups directory if it doesn't exist
            if (!is_dir($backupsDir)) {
                if (!mkdir($backupsDir, 0755, true) && !is_dir($backupsDir)) {
                    $io->error(sprintf('Failed to create backups directory: %s', $backupsDir));
                    return Command::FAILURE;
                }
            }

            // Backup frames, timesheets, and local projects
            $framesBackedUp = $this->backupFrames($zebraDir, $backupsDir, $io);
            $timesheetsBackedUp = $this->backupTimesheets($zebraDir, $backupsDir, $io);
            $localProjectsBackedUp = $this->backupLocalProjects($zebraDir, $backupsDir, $io);

            // If no files exist, warn but don't fail
            if (!$framesBackedUp && !$timesheetsBackedUp && !$localProjectsBackedUp) {
                $io->warning('No frames, timesheets, or local projects files found to backup.');
                return Command::SUCCESS;
            }

            // Update marker file to record today's backup
            $this->updateMarkerFile();

            // Build success message
            $messages = [];
            if ($framesBackedUp) {
                $messages[] = 'frames';
            }
            if ($timesheetsBackedUp) {
                $messages[] = 'timesheets';
            }
            if ($localProjectsBackedUp) {
                $messages[] = 'local projects';
            }
            if (count($messages) === 1) {
                $message = $messages[0];
            } elseif (count($messages) === 2) {
                $message = implode(' and ', $messages);
            } else {
                $last = array_pop($messages);
                $message = implode(', ', $messages) . ', and ' . $last;
            }
            $io->success(sprintf('%s backed up successfully', $message));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Backup failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * Check if a backup exists for today (UTC date) using marker file.
     *
     * @return bool
     */
    public function hasBackupForToday(): bool
    {
        try {
            $markerPath = $this->getMarkerFilePath();

            if (!file_exists($markerPath)) {
                return false;
            }

            $markerContent = file_get_contents($markerPath);
            if ($markerContent === false) {
                return false;
            }

            $lastBackupDate = trim($markerContent);
            $today = Carbon::now('UTC')->format('Y-m-d');

            return $lastBackupDate === $today;
        } catch (\Exception $e) {
            // If we can't check, assume no backup exists (fail-safe)
            return false;
        }
    }

    /**
     * Execute backup silently (without output).
     * Used for automatic daily backups.
     *
     * @return bool True if backup succeeded, false otherwise
     */
    public function executeSilently(): bool
    {
        try {
            $homeDir = $this->getHomeDirectory();
            $zebraDir = $homeDir . DIRECTORY_SEPARATOR . '.zebra';
            $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . self::BACKUPS_DIRECTORY;

            // Create backups directory if it doesn't exist
            if (!is_dir($backupsDir)) {
                if (!mkdir($backupsDir, 0755, true) && !is_dir($backupsDir)) {
                    return false;
                }
            }

            // Backup frames, timesheets, and local projects
            $framesBackedUp = $this->backupFrames($zebraDir, $backupsDir, null);
            $timesheetsBackedUp = $this->backupTimesheets($zebraDir, $backupsDir, null);
            $localProjectsBackedUp = $this->backupLocalProjects($zebraDir, $backupsDir, null);

            // If no files exist, return false
            if (!$framesBackedUp && !$timesheetsBackedUp && !$localProjectsBackedUp) {
                return false;
            }

            // Update marker file to record today's backup
            $this->updateMarkerFile();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the path to the marker file.
     *
     * @return string
     */
    private function getMarkerFilePath(): string
    {
        $homeDir = $this->getHomeDirectory();
        $zebraDir = $homeDir . DIRECTORY_SEPARATOR . '.zebra';
        return $zebraDir . DIRECTORY_SEPARATOR . self::MARKER_FILENAME;
    }

    /**
     * Backup frames.json file.
     *
     * @param string $zebraDir
     * @param string $backupsDir
     * @param SymfonyStyle|null $io
     * @return bool True if backup succeeded, false otherwise
     */
    private function backupFrames(string $zebraDir, string $backupsDir, ?SymfonyStyle $io): bool
    {
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . self::FRAMES_FILENAME;

        // Check if frames.json exists
        if (!file_exists($framesPath)) {
            return false;
        }

        // Generate backup filename with UTC timestamp
        $timestamp = Carbon::now('UTC');
        $timestampString = $timestamp->format('Y-m-d_H-i-s') . '-UTC';
        $backupFilename = sprintf('frames-%s.json', $timestampString);
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Copy frames.json to backup location
        if (!copy($framesPath, $backupPath)) {
            if ($io !== null) {
                $io->error(sprintf('Failed to create frames backup: %s', $backupPath));
            }
            return false;
        }

        return true;
    }

    /**
     * Backup timesheets.json file.
     *
     * @param string $zebraDir
     * @param string $backupsDir
     * @param SymfonyStyle|null $io
     * @return bool True if backup succeeded, false otherwise
     */
    private function backupTimesheets(string $zebraDir, string $backupsDir, ?SymfonyStyle $io): bool
    {
        $timesheetsPath = $zebraDir . DIRECTORY_SEPARATOR . self::TIMESHEETS_FILENAME;

        // Check if timesheets.json exists
        if (!file_exists($timesheetsPath)) {
            return false;
        }

        // Generate backup filename with UTC timestamp
        $timestamp = Carbon::now('UTC');
        $timestampString = $timestamp->format('Y-m-d_H-i-s') . '-UTC';
        $backupFilename = sprintf('timesheets-%s.json', $timestampString);
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Copy timesheets.json to backup location
        if (!copy($timesheetsPath, $backupPath)) {
            if ($io !== null) {
                $io->error(sprintf('Failed to create timesheets backup: %s', $backupPath));
            }
            return false;
        }

        return true;
    }

    /**
     * Backup local-projects.json file.
     *
     * @param string $zebraDir
     * @param string $backupsDir
     * @param SymfonyStyle|null $io
     * @return bool True if backup succeeded, false otherwise
     */
    private function backupLocalProjects(string $zebraDir, string $backupsDir, ?SymfonyStyle $io): bool
    {
        $localProjectsPath = $zebraDir . DIRECTORY_SEPARATOR . self::LOCAL_PROJECTS_FILENAME;

        // Check if local-projects.json exists
        if (!file_exists($localProjectsPath)) {
            return false;
        }

        // Generate backup filename with UTC timestamp
        $timestamp = Carbon::now('UTC');
        $timestampString = $timestamp->format('Y-m-d_H-i-s') . '-UTC';
        $backupFilename = sprintf('local-projects-%s.json', $timestampString);
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Copy local-projects.json to backup location
        if (!copy($localProjectsPath, $backupPath)) {
            if ($io !== null) {
                $io->error(
                    sprintf('Failed to create local projects backup: %s', $backupPath)
                );
            }
            return false;
        }

        return true;
    }

    /**
     * Update the marker file with today's date (UTC).
     *
     * @return void
     */
    private function updateMarkerFile(): void
    {
        try {
            $markerPath = $this->getMarkerFilePath();
            $today = Carbon::now('UTC')->format('Y-m-d');

            // Ensure .zebra directory exists
            $zebraDir = dirname($markerPath);
            if (!is_dir($zebraDir) && !mkdir($zebraDir, 0755, true) && !is_dir($zebraDir)) {
                // If we can't create directory, silently fail (marker file is not critical)
                return;
            }

            file_put_contents($markerPath, $today);
        } catch (\Exception $e) {
            // Silently fail - marker file update is not critical for backup success
        }
    }
}
