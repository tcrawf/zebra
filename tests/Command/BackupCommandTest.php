<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\BackupCommand;

class BackupCommandTest extends TestCase
{
    private BackupCommand $command;
    private CommandTester $commandTester;
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        $this->command = new BackupCommand();

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        // Create temporary directory for testing
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zebra_backup_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Store original environment variable
        $this->originalHome = getenv('HOME') ?: '';
    }

    protected function tearDown(): void
    {
        // Restore original environment variable
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    public function testBackupCommandExists(): void
    {
        $this->assertInstanceOf(BackupCommand::class, $this->command);
    }

    public function testBackupCommandName(): void
    {
        $this->assertEquals('backup', $this->command->getName());
    }

    public function testBackupCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testBackupCreatesBackupsDirectory(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and frames.json
        mkdir($zebraDir, 0755, true);
        file_put_contents($framesPath, json_encode(['test' => 'data']));

        // Execute backup command
        $this->commandTester->execute([]);

        // Verify backups directory was created
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertDirectoryExists($backupsDir);
    }

    public function testBackupCopiesFramesFile(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and frames.json with test data
        mkdir($zebraDir, 0755, true);
        $testData = ['frame1' => ['uuid' => '123', 'start' => '2024-01-01T00:00:00Z']];
        file_put_contents($framesPath, json_encode($testData, JSON_PRETTY_PRINT));

        // Execute backup command
        $this->commandTester->execute([]);

        // Verify backup was created
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertDirectoryExists($backupsDir);

        // Find backup file
        $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $this->assertCount(1, $backupFiles, 'Expected exactly one backup file');

        $backupPath = $backupFiles[0];

        // Verify backup file content matches original
        $backupContent = file_get_contents($backupPath);
        $this->assertNotFalse($backupContent);
        $backupData = json_decode($backupContent, true);
        $this->assertEquals($testData, $backupData);
    }

    public function testBackupFilenameContainsUtcTimestamp(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and frames.json
        mkdir($zebraDir, 0755, true);
        file_put_contents($framesPath, json_encode(['test' => 'data']));

        // Execute backup command
        $this->commandTester->execute([]);

        // Verify backup filename format
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $this->assertCount(1, $backupFiles);

        $backupFilename = basename($backupFiles[0]);

        // Check filename format: frames-YYYY-MM-DD_HH-MM-SS-UTC.json
        $this->assertStringStartsWith('frames-', $backupFilename);
        $this->assertStringEndsWith('-UTC.json', $backupFilename);

        // Extract timestamp part
        $timestampPart = str_replace(['frames-', '-UTC.json'], '', $backupFilename);

        // Verify timestamp format: YYYY-MM-DD_HH-MM-SS
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $timestampPart);
    }

    public function testBackupSucceedsWhenNoFiles(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';

        // Create .zebra directory but no frames.json or timesheets.json
        mkdir($zebraDir, 0755, true);

        // Execute backup command
        $this->commandTester->execute([]);

        // Should succeed but warn
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No frames, timesheets, or local projects files found', $output);
    }

    public function testBackupHandlesExistingBackupsDirectory(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory, backups directory, and frames.json
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        file_put_contents($framesPath, json_encode(['test' => 'data']));

        // Execute backup command
        $this->commandTester->execute([]);

        // Should succeed
        $this->assertEquals(0, $this->commandTester->getStatusCode());

        // Verify backup was created
        $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $this->assertCount(1, $backupFiles);
    }

    public function testMultipleBackupsCanExist(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and frames.json
        mkdir($zebraDir, 0755, true);
        file_put_contents($framesPath, json_encode(['test' => 'data']));

        // Execute backup command twice (with small delay to ensure different timestamps)
        $this->commandTester->execute([]);
        usleep(1000000); // 1 second delay
        $this->commandTester->execute([]);

        // Should succeed
        $this->assertEquals(0, $this->commandTester->getStatusCode());

        // Verify both backups exist
        $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $this->assertCount(2, $backupFiles);
    }

    public function testHasBackupForTodayReturnsFalseWhenMarkerFileDoesNotExist(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        mkdir($zebraDir, 0755, true);

        // No marker file exists
        $this->assertFalse($this->command->hasBackupForToday());
    }

    public function testHasBackupForTodayReturnsTrueWhenMarkerFileHasTodayDate(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $markerPath = $zebraDir . DIRECTORY_SEPARATOR . '.last_backup_date';
        mkdir($zebraDir, 0755, true);

        // Create marker file with today's date
        $today = (new \Carbon\Carbon('UTC'))->format('Y-m-d');
        file_put_contents($markerPath, $today);

        $this->assertTrue($this->command->hasBackupForToday());
    }

    public function testHasBackupForTodayReturnsFalseWhenMarkerFileHasYesterdayDate(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $markerPath = $zebraDir . DIRECTORY_SEPARATOR . '.last_backup_date';
        mkdir($zebraDir, 0755, true);

        // Create marker file with yesterday's date
        $yesterday = (new \Carbon\Carbon('UTC'))->subDay()->format('Y-m-d');
        file_put_contents($markerPath, $yesterday);

        $this->assertFalse($this->command->hasBackupForToday());
    }

    public function testExecuteSilentlyCreatesBackupAndMarkerFile(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $markerPath = $zebraDir . DIRECTORY_SEPARATOR . '.last_backup_date';

        // Create .zebra directory and frames.json
        mkdir($zebraDir, 0755, true);
        $testData = ['frame1' => ['uuid' => '123']];
        file_put_contents($framesPath, json_encode($testData, JSON_PRETTY_PRINT));

        // Execute backup silently
        $result = $this->command->executeSilently();

        // Verify backup succeeded
        $this->assertTrue($result);
        $this->assertDirectoryExists($backupsDir);

        // Verify backup file was created
        $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $this->assertCount(1, $backupFiles);

        // Verify marker file was created with today's date
        $this->assertFileExists($markerPath);
        $markerContent = file_get_contents($markerPath);
        $today = (new \Carbon\Carbon('UTC'))->format('Y-m-d');
        $this->assertEquals($today, trim($markerContent));
    }

    public function testExecuteSilentlyReturnsFalseWhenNoFiles(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        mkdir($zebraDir, 0755, true);

        // No frames.json or timesheets.json file
        $result = $this->command->executeSilently();

        $this->assertFalse($result);
    }

    public function testBackupCopiesTimesheetsFile(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $timesheetsPath = $zebraDir . DIRECTORY_SEPARATOR . 'timesheets.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and timesheets.json with test data
        mkdir($zebraDir, 0755, true);
        $testData = ['timesheet1' => ['uuid' => '456', 'date' => '2024-01-01']];
        file_put_contents($timesheetsPath, json_encode($testData, JSON_PRETTY_PRINT));

        // Execute backup command
        $this->commandTester->execute([]);

        // Verify backup was created
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertDirectoryExists($backupsDir);

        // Find backup file
        $backupFiles = glob($backupsDir . DIRECTORY_SEPARATOR . 'timesheets-*.json');
        $this->assertCount(1, $backupFiles, 'Expected exactly one timesheet backup file');

        $backupPath = $backupFiles[0];

        // Verify backup file content matches original
        $backupContent = file_get_contents($backupPath);
        $this->assertNotFalse($backupContent);
        $backupData = json_decode($backupContent, true);
        $this->assertEquals($testData, $backupData);
    }

    public function testBackupBacksUpBothFramesAndTimesheets(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $timesheetsPath = $zebraDir . DIRECTORY_SEPARATOR . 'timesheets.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and both files
        mkdir($zebraDir, 0755, true);
        $framesData = ['frame1' => ['uuid' => '123']];
        $timesheetsData = ['timesheet1' => ['uuid' => '456']];
        file_put_contents($framesPath, json_encode($framesData, JSON_PRETTY_PRINT));
        file_put_contents($timesheetsPath, json_encode($timesheetsData, JSON_PRETTY_PRINT));

        // Execute backup command
        $this->commandTester->execute([]);

        // Verify both backups were created
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertDirectoryExists($backupsDir);

        $framesBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $timesheetsBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'timesheets-*.json');

        $this->assertCount(1, $framesBackups, 'Expected exactly one frames backup');
        $this->assertCount(1, $timesheetsBackups, 'Expected exactly one timesheets backup');

        // Verify backup contents
        $framesBackupContent = file_get_contents($framesBackups[0]);
        $this->assertNotFalse($framesBackupContent);
        $framesBackupData = json_decode($framesBackupContent, true);
        $this->assertEquals($framesData, $framesBackupData);

        $timesheetsBackupContent = file_get_contents($timesheetsBackups[0]);
        $this->assertNotFalse($timesheetsBackupContent);
        $timesheetsBackupData = json_decode($timesheetsBackupContent, true);
        $this->assertEquals($timesheetsData, $timesheetsBackupData);

        // Verify success message mentions both
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('frames and timesheets', $output);
    }

    public function testExecuteSilentlyBacksUpBothFramesAndTimesheets(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $timesheetsPath = $zebraDir . DIRECTORY_SEPARATOR . 'timesheets.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and both files
        mkdir($zebraDir, 0755, true);
        $framesData = ['frame1' => ['uuid' => '123']];
        $timesheetsData = ['timesheet1' => ['uuid' => '456']];
        file_put_contents($framesPath, json_encode($framesData, JSON_PRETTY_PRINT));
        file_put_contents($timesheetsPath, json_encode($timesheetsData, JSON_PRETTY_PRINT));

        // Execute backup silently
        $result = $this->command->executeSilently();

        // Verify backup succeeded
        $this->assertTrue($result);
        $this->assertDirectoryExists($backupsDir);

        // Verify both backup files were created
        $framesBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $timesheetsBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'timesheets-*.json');

        $this->assertCount(1, $framesBackups);
        $this->assertCount(1, $timesheetsBackups);
    }

    public function testExecuteSilentlyReturnsTrueWhenOnlyFramesExists(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and frames.json only
        mkdir($zebraDir, 0755, true);
        file_put_contents($framesPath, json_encode(['frame1' => ['uuid' => '123']]));

        // Execute backup silently
        $result = $this->command->executeSilently();

        // Should succeed (backing up frames only)
        $this->assertTrue($result);
        $this->assertDirectoryExists($backupsDir);

        $framesBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json');
        $this->assertCount(1, $framesBackups);
    }

    public function testExecuteSilentlyReturnsTrueWhenOnlyTimesheetsExists(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $timesheetsPath = $zebraDir . DIRECTORY_SEPARATOR . 'timesheets.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create .zebra directory and timesheets.json only
        mkdir($zebraDir, 0755, true);
        file_put_contents($timesheetsPath, json_encode(['timesheet1' => ['uuid' => '456']]));

        // Execute backup silently
        $result = $this->command->executeSilently();

        // Should succeed (backing up timesheets only)
        $this->assertTrue($result);
        $this->assertDirectoryExists($backupsDir);

        $timesheetsBackups = glob($backupsDir . DIRECTORY_SEPARATOR . 'timesheets-*.json');
        $this->assertCount(1, $timesheetsBackups);
    }

    public function testExecuteCreatesMarkerFileAfterBackup(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $markerPath = $zebraDir . DIRECTORY_SEPARATOR . '.last_backup_date';

        // Create .zebra directory and frames.json
        mkdir($zebraDir, 0755, true);
        file_put_contents($framesPath, json_encode(['test' => 'data']));

        // Execute backup command
        $this->commandTester->execute([]);

        // Verify marker file was created with today's date
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($markerPath);
        $markerContent = file_get_contents($markerPath);
        $today = (new \Carbon\Carbon('UTC'))->format('Y-m-d');
        $this->assertEquals($today, trim($markerContent));
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir
     * @return void
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
