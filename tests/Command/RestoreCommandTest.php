<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\RestoreCommand;

class RestoreCommandTest extends TestCase
{
    private RestoreCommand $command;
    private CommandTester $commandTester;
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        $this->command = new RestoreCommand();

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        // Create temporary directory for testing
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zebra_restore_test_' . uniqid();
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

    public function testRestoreCommandExists(): void
    {
        $this->assertInstanceOf(RestoreCommand::class, $this->command);
    }

    public function testRestoreCommandName(): void
    {
        $this->assertEquals('restore', $this->command->getName());
    }

    public function testRestoreCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testRestoreFailsWhenNoTypeOption(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        mkdir($zebraDir, 0755, true);

        // No --type option
        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('--type option is required', $output);
    }

    public function testRestoreFailsWhenNoBackupsDirectory(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        mkdir($zebraDir, 0755, true);

        // No backups directory
        $this->commandTester->execute(['--type' => 'frames']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No backups directory found', $output);
    }

    public function testRestoreFailsWhenNoBackupFiles(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        // Empty backups directory
        $this->commandTester->execute(['--type' => 'frames'], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No frames backup files found', $output);
    }

    public function testRestoreWithBackupFilenameArgument(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'frames-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, and backup file
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        $backupData = ['frame1' => ['uuid' => '123', 'start' => '2024-01-01T00:00:00Z']];
        file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

        // Execute restore command with backup filename and type
        $this->commandTester->execute(['backup' => $backupFilename, '--type' => 'frames']);

        // Verify restore succeeded
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($framesPath);

        // Verify frames.json content matches backup
        $restoredContent = file_get_contents($framesPath);
        $this->assertNotFalse($restoredContent);
        $restoredData = json_decode($restoredContent, true);
        $this->assertEquals($backupData, $restoredData);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frames restored from backup', $output);
    }

    public function testRestoreCreatesBackupOfCurrentFrames(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'frames-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, current frames.json, and backup file
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        $currentData = ['current' => 'data'];
        file_put_contents($framesPath, json_encode($currentData, JSON_PRETTY_PRINT));
        $backupData = ['frame1' => ['uuid' => '123']];
        file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

        // Count backups before restore
        $backupsBefore = count(glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json'));

        // Execute restore command
        $this->commandTester->execute(['backup' => $backupFilename, '--type' => 'frames']);

        // Verify restore succeeded
        $this->assertEquals(0, $this->commandTester->getStatusCode());

        // Verify a backup of current frames.json was created
        $backupsAfter = count(glob($backupsDir . DIRECTORY_SEPARATOR . 'frames-*.json'));
        $this->assertEquals($backupsBefore + 1, $backupsAfter);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Current frames.json backed up', $output);
    }

    public function testRestoreFailsWithInvalidBackupFilename(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        // Try to restore non-existent backup
        $this->commandTester->execute(['backup' => 'nonexistent-backup.json', '--type' => 'frames']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup file not found', $output);
    }

    public function testRestoreFailsWithInvalidJson(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'frames-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, and invalid JSON backup file
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        file_put_contents($backupPath, 'invalid json content');

        // Execute restore command
        $this->commandTester->execute(['backup' => $backupFilename, '--type' => 'frames']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Invalid JSON', $output);
    }

    public function testRestoreWorksWhenNoCurrentFramesFile(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $framesPath = $zebraDir . DIRECTORY_SEPARATOR . 'frames.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'frames-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, and backup file
        // But no current frames.json
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        $backupData = ['frame1' => ['uuid' => '123']];
        file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

        // Execute restore command
        $this->commandTester->execute(['backup' => $backupFilename, '--type' => 'frames']);

        // Verify restore succeeded and frames.json was created
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($framesPath);

        // Verify frames.json content matches backup
        $restoredContent = file_get_contents($framesPath);
        $this->assertNotFalse($restoredContent);
        $restoredData = json_decode($restoredContent, true);
        $this->assertEquals($backupData, $restoredData);
    }

    public function testRestoreTimesheetsWithBackupFilenameArgument(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $timesheetsPath = $zebraDir . DIRECTORY_SEPARATOR . 'timesheets.json';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'timesheets-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, and backup file
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        $backupData = ['timesheet1' => ['uuid' => '456', 'date' => '2024-01-01']];
        file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

        // Execute restore command with backup filename and type
        $this->commandTester->execute(['backup' => $backupFilename, '--type' => 'timesheets']);

        // Verify restore succeeded
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($timesheetsPath);

        // Verify timesheets.json content matches backup
        $restoredContent = file_get_contents($timesheetsPath);
        $this->assertNotFalse($restoredContent);
        $restoredData = json_decode($restoredContent, true);
        $this->assertEquals($backupData, $restoredData);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Timesheets restored from backup', $output);
    }

    public function testRestoreFailsWithInvalidType(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        mkdir($zebraDir, 0755, true);

        // Try to restore with invalid type
        $this->commandTester->execute(['--type' => 'invalid']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Invalid type', $output);
    }

    public function testRestoreFailsWhenBackupFilenameDoesNotMatchType(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'timesheets-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, and backup file
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        file_put_contents($backupPath, json_encode(['test' => 'data']));

        // Try to restore timesheet backup with frames type
        $this->commandTester->execute(['backup' => $backupFilename, '--type' => 'frames']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison (output may be wrapped across lines by Symfony Console)
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString('Backup filename', $normalizedOutput);
        $this->assertStringContainsString('match type', $normalizedOutput);
        $this->assertStringContainsString('frames', $normalizedOutput);
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
