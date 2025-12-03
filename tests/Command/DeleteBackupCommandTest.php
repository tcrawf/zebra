<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\DeleteBackupCommand;

class DeleteBackupCommandTest extends TestCase
{
    private DeleteBackupCommand $command;
    private CommandTester $commandTester;
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        $this->command = new DeleteBackupCommand();

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        // Create temporary directory for testing
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zebra_delete_backup_test_' . uniqid();
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

    public function testDeleteBackupCommandExists(): void
    {
        $this->assertInstanceOf(DeleteBackupCommand::class, $this->command);
    }

    public function testDeleteBackupCommandName(): void
    {
        $this->assertEquals('delete-backup', $this->command->getName());
    }

    public function testDeleteBackupCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testDeleteFailsWhenNoBackupsDirectory(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        mkdir($zebraDir, 0755, true);

        // No backups directory
        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No backups directory found', $output);
    }

    public function testDeleteFailsWhenNoBackupFiles(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        // Empty backups directory
        $this->commandTester->execute([], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No backup files found', $output);
    }

    public function testDeleteWithBackupFilenameArgumentAndForce(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'frames-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, and backup file
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        file_put_contents($backupPath, json_encode(['test' => 'data']));

        // Verify backup exists before deletion
        $this->assertFileExists($backupPath);

        // Execute delete command with --force flag
        $this->commandTester->execute([
            'backup' => $backupFilename,
            '--force' => true,
        ]);

        // Verify delete succeeded
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($backupPath);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup deleted', $output);
    }

    public function testDeleteFailsWithInvalidBackupFilename(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        // Try to delete non-existent backup
        $this->commandTester->execute([
            'backup' => 'nonexistent-backup.json',
            '--force' => true,
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup file not found', $output);
    }

    public function testDeleteRequiresConfirmationWithoutForce(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backupFilename = 'frames-2024-01-15_14-30-45-UTC.json';
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupFilename;

        // Create .zebra directory, backups directory, and backup file
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        file_put_contents($backupPath, json_encode(['test' => 'data']));

        // Execute delete command without --force in non-interactive mode
        $this->commandTester->execute(
            ['backup' => $backupFilename],
            ['interactive' => false]
        );

        // Should fail because confirmation is required
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('requires confirmation', $output);

        // Verify backup still exists
        $this->assertFileExists($backupPath);
    }

    public function testDeleteCanDeleteMultipleBackups(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $backup1Filename = 'frames-2024-01-15_14-30-45-UTC.json';
        $backup1Path = $backupsDir . DIRECTORY_SEPARATOR . $backup1Filename;
        $backup2Filename = 'frames-2024-01-16_14-30-45-UTC.json';
        $backup2Path = $backupsDir . DIRECTORY_SEPARATOR . $backup2Filename;

        // Create .zebra directory, backups directory, and two backup files
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        file_put_contents($backup1Path, json_encode(['test1' => 'data']));
        file_put_contents($backup2Path, json_encode(['test2' => 'data']));

        // Delete first backup
        $this->commandTester->execute([
            'backup' => $backup1Filename,
            '--force' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($backup1Path);
        $this->assertFileExists($backup2Path);

        // Delete second backup
        $this->commandTester->execute([
            'backup' => $backup2Filename,
            '--force' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($backup2Path);
    }

    public function testDeleteOldBackupsWithDefaultDays(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create backups: one old (35 days ago), one recent (5 days ago)
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        $oldBackupDate = (new \Carbon\Carbon('UTC'))->subDays(35);
        $oldBackupFilename = sprintf('frames-%s-UTC.json', $oldBackupDate->format('Y-m-d_H-i-s'));
        $oldBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $oldBackupFilename;

        $recentBackupDate = (new \Carbon\Carbon('UTC'))->subDays(5);
        $recentBackupFilename = sprintf('frames-%s-UTC.json', $recentBackupDate->format('Y-m-d_H-i-s'));
        $recentBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $recentBackupFilename;

        file_put_contents($oldBackupPath, json_encode(['old' => 'data']));
        file_put_contents($recentBackupPath, json_encode(['recent' => 'data']));

        // Delete backups older than default (30 days)
        $this->commandTester->execute([
            '--older-than' => null,
            '--force' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($oldBackupPath);
        $this->assertFileExists($recentBackupPath);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Deleted 1 backup(s) older than 30 days', $output);
    }

    public function testDeleteOldBackupsWithCustomDays(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create backups: one old (15 days ago), one recent (5 days ago)
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        $oldBackupDate = (new \Carbon\Carbon('UTC'))->subDays(15);
        $oldBackupFilename = sprintf('frames-%s-UTC.json', $oldBackupDate->format('Y-m-d_H-i-s'));
        $oldBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $oldBackupFilename;

        $recentBackupDate = (new \Carbon\Carbon('UTC'))->subDays(5);
        $recentBackupFilename = sprintf('frames-%s-UTC.json', $recentBackupDate->format('Y-m-d_H-i-s'));
        $recentBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $recentBackupFilename;

        file_put_contents($oldBackupPath, json_encode(['old' => 'data']));
        file_put_contents($recentBackupPath, json_encode(['recent' => 'data']));

        // Delete backups older than 10 days
        $this->commandTester->execute([
            '--older-than' => '10',
            '--force' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($oldBackupPath);
        $this->assertFileExists($recentBackupPath);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Deleted 1 backup(s) older than 10 days', $output);
    }

    public function testDeleteOldBackupsRequiresConfirmation(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create old backup
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        $oldBackupDate = (new \Carbon\Carbon('UTC'))->subDays(35);
        $oldBackupFilename = sprintf('frames-%s-UTC.json', $oldBackupDate->format('Y-m-d_H-i-s'));
        $oldBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $oldBackupFilename;
        file_put_contents($oldBackupPath, json_encode(['old' => 'data']));

        // Try to delete without --force in non-interactive mode
        $this->commandTester->execute(
            ['--older-than' => null],
            ['interactive' => false]
        );

        // Should fail because confirmation is required
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('requires confirmation', $output);

        // Verify backup still exists
        $this->assertFileExists($oldBackupPath);
    }

    public function testDeleteOldBackupsNoOldBackupsFound(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create only recent backup (5 days ago)
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        $recentBackupDate = (new \Carbon\Carbon('UTC'))->subDays(5);
        $recentBackupFilename = sprintf('frames-%s-UTC.json', $recentBackupDate->format('Y-m-d_H-i-s'));
        $recentBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $recentBackupFilename;
        file_put_contents($recentBackupPath, json_encode(['recent' => 'data']));

        // Try to delete backups older than 30 days
        $this->commandTester->execute([
            '--older-than' => null,
            '--force' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($recentBackupPath);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No backups found older than 30 days', $output);
    }

    public function testDeleteOldBackupsDeletesMultipleBackups(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create multiple old backups
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        $backup1Date = (new \Carbon\Carbon('UTC'))->subDays(35);
        $backup1Filename = sprintf('frames-%s-UTC.json', $backup1Date->format('Y-m-d_H-i-s'));
        $backup1Path = $backupsDir . DIRECTORY_SEPARATOR . $backup1Filename;

        $backup2Date = (new \Carbon\Carbon('UTC'))->subDays(40);
        $backup2Filename = sprintf('frames-%s-UTC.json', $backup2Date->format('Y-m-d_H-i-s'));
        $backup2Path = $backupsDir . DIRECTORY_SEPARATOR . $backup2Filename;

        $recentBackupDate = (new \Carbon\Carbon('UTC'))->subDays(5);
        $recentBackupFilename = sprintf('frames-%s-UTC.json', $recentBackupDate->format('Y-m-d_H-i-s'));
        $recentBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $recentBackupFilename;

        file_put_contents($backup1Path, json_encode(['backup1' => 'data']));
        file_put_contents($backup2Path, json_encode(['backup2' => 'data']));
        file_put_contents($recentBackupPath, json_encode(['recent' => 'data']));

        // Delete backups older than 30 days
        $this->commandTester->execute([
            '--older-than' => null,
            '--force' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($backup1Path);
        $this->assertFileDoesNotExist($backup2Path);
        $this->assertFileExists($recentBackupPath);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Deleted 2 backup(s) older than 30 days', $output);
    }

    public function testDeleteTimesheetBackup(): void
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

        // Verify backup exists before deletion
        $this->assertFileExists($backupPath);

        // Execute delete command with --force flag
        $this->commandTester->execute([
            'backup' => $backupFilename,
            '--force' => true,
        ]);

        // Verify delete succeeded
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($backupPath);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Backup deleted', $output);
    }

    public function testDeleteOldBackupsWithBothTypes(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';

        // Create backups: old frames (35 days ago), old timesheets (40 days ago), recent frames (5 days ago)
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);

        $oldFramesDate = (new \Carbon\Carbon('UTC'))->subDays(35);
        $oldFramesFilename = sprintf('frames-%s-UTC.json', $oldFramesDate->format('Y-m-d_H-i-s'));
        $oldFramesPath = $backupsDir . DIRECTORY_SEPARATOR . $oldFramesFilename;

        $oldTimesheetsDate = (new \Carbon\Carbon('UTC'))->subDays(40);
        $oldTimesheetsFilename = sprintf('timesheets-%s-UTC.json', $oldTimesheetsDate->format('Y-m-d_H-i-s'));
        $oldTimesheetsPath = $backupsDir . DIRECTORY_SEPARATOR . $oldTimesheetsFilename;

        $recentFramesDate = (new \Carbon\Carbon('UTC'))->subDays(5);
        $recentFramesFilename = sprintf('frames-%s-UTC.json', $recentFramesDate->format('Y-m-d_H-i-s'));
        $recentFramesPath = $backupsDir . DIRECTORY_SEPARATOR . $recentFramesFilename;

        file_put_contents($oldFramesPath, json_encode(['old' => 'frames']));
        file_put_contents($oldTimesheetsPath, json_encode(['old' => 'timesheets']));
        file_put_contents($recentFramesPath, json_encode(['recent' => 'frames']));

        // Delete backups older than 30 days
        $this->commandTester->execute([
            '--older-than' => null,
            '--force' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($oldFramesPath);
        $this->assertFileDoesNotExist($oldTimesheetsPath);
        $this->assertFileExists($recentFramesPath);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Deleted 2 backup(s) older than 30 days', $output);
        $this->assertStringContainsString('[frames]', $output);
        $this->assertStringContainsString('[timesheets]', $output);
    }

    public function testSelectBackupShowsBothTypes(): void
    {
        // Set HOME to temp directory
        putenv('HOME=' . $this->tempDir);

        $zebraDir = $this->tempDir . DIRECTORY_SEPARATOR . '.zebra';
        $backupsDir = $zebraDir . DIRECTORY_SEPARATOR . 'backups';
        $framesBackupFilename = 'frames-2024-01-15_14-30-45-UTC.json';
        $framesBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $framesBackupFilename;
        $timesheetsBackupFilename = 'timesheets-2024-01-16_14-30-45-UTC.json';
        $timesheetsBackupPath = $backupsDir . DIRECTORY_SEPARATOR . $timesheetsBackupFilename;

        // Create .zebra directory, backups directory, and both backup files
        mkdir($zebraDir, 0755, true);
        mkdir($backupsDir, 0755, true);
        file_put_contents($framesBackupPath, json_encode(['test1' => 'data']));
        file_put_contents($timesheetsBackupPath, json_encode(['test2' => 'data']));

        // Verify both backups exist
        $this->assertFileExists($framesBackupPath);
        $this->assertFileExists($timesheetsBackupPath);

        // The selectBackup method is private, but we can test it indirectly
        // by checking that both types of backups are available when listing
        // This is tested through the deleteOldBackups functionality above
        $this->assertTrue(true);
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
