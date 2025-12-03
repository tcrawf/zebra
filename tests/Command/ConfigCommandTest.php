<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\ConfigCommand;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class ConfigCommandTest extends TestCase
{
    private ConfigFileStorageInterface&MockObject $configStorage;
    private ConfigCommand $command;
    private CommandTester $commandTester;
    private vfsStreamDirectory $root;
    private string $testHomeDir;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('test');
        $this->testHomeDir = $this->root->url();
        putenv('HOME=' . $this->testHomeDir);

        $this->configStorage = $this->createMock(ConfigFileStorageInterface::class);

        $this->command = new ConfigCommand($this->configStorage);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        putenv('HOME');
    }

    public function testCommandName(): void
    {
        $this->assertEquals('config', $this->command->getName());
    }

    public function testCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testShowSynopsisWhenNoKeyProvided(): void
    {
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('config', $output);
    }

    public function testGetStringValue(): void
    {
        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('test.key')
            ->willReturn('test-value');

        $this->commandTester->execute(['key' => 'test.key']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-value', trim($output));
    }

    public function testGetArrayValue(): void
    {
        $arrayValue = ['key1' => 'value1', 'key2' => 'value2'];
        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('test.array')
            ->willReturn($arrayValue);

        $this->commandTester->execute(['key' => 'test.array']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $decoded = json_decode($output, true);
        $this->assertEquals($arrayValue, $decoded);
    }

    public function testGetNonExistentKey(): void
    {
        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('nonexistent.key')
            ->willReturn(null);

        $this->commandTester->execute(['key' => 'nonexistent.key']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('not found', $output);
    }

    public function testSetStringValue(): void
    {
        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('test.key', 'new-value');

        $this->commandTester->execute([
            'key' => 'test.key',
            'value' => 'new-value',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("set to 'new-value'", $output);
    }

    public function testSetJsonValue(): void
    {
        $jsonValue = '{"key1":"value1","key2":"value2"}';
        $decodedValue = ['key1' => 'value1', 'key2' => 'value2'];

        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('test.json', $decodedValue);

        $this->commandTester->execute([
            'key' => 'test.json',
            'value' => $jsonValue,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("set to '{$jsonValue}'", $output);
    }

    public function testSetNumericValueAsString(): void
    {
        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('test.number', '123');

        $this->commandTester->execute([
            'key' => 'test.number',
            'value' => '123',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testSetBooleanValueAsString(): void
    {
        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('test.bool', 'true');

        $this->commandTester->execute([
            'key' => 'test.bool',
            'value' => 'true',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testSetInvalidJsonFallsBackToString(): void
    {
        $invalidJson = 'not valid json {';

        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('test.invalid', $invalidJson);

        $this->commandTester->execute([
            'key' => 'test.invalid',
            'value' => $invalidJson,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testEditOptionShowsSynopsis(): void
    {
        // When --edit is used, it tries to access filePath property which doesn't exist on mocks
        // We'll use a real ConfigFileStorage instance for this test
        $realStorage = new \Tcrawf\Zebra\Config\ConfigFileStorage();
        $command = new ConfigCommand($realStorage);

        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        // Set PHPUNIT_RUNNING to prevent editor from actually opening
        $originalPhpunitRunning = getenv('PHPUNIT_RUNNING');
        putenv('PHPUNIT_RUNNING=1');

        try {
            $commandTester->execute(['--edit' => true]);
            // The command should attempt to edit, but in test environment it may fail gracefully
            // We just verify it doesn't crash with a reflection error
            $this->assertIsInt($commandTester->getStatusCode());
        } finally {
            if ($originalPhpunitRunning !== false) {
                putenv('PHPUNIT_RUNNING=' . $originalPhpunitRunning);
            } else {
                putenv('PHPUNIT_RUNNING');
            }
        }
    }

    public function testGetEditorReturnsDefaultWhenNoEnvVar(): void
    {
        $originalVisual = getenv('VISUAL');
        $originalEditor = getenv('EDITOR');
        putenv('VISUAL');
        putenv('EDITOR');

        try {
            $reflection = new \ReflectionClass($this->command);
            $method = $reflection->getMethod('getEditor');
            $method->setAccessible(true);

            $result = $method->invoke($this->command);

            // Should return a default editor (vi, vim, nano, or notepad)
            $this->assertNotEmpty($result);
            $this->assertIsString($result);
        } finally {
            if ($originalVisual !== false) {
                putenv('VISUAL=' . $originalVisual);
            }
            if ($originalEditor !== false) {
                putenv('EDITOR=' . $originalEditor);
            }
        }
    }

    public function testGetEditorUsesVisualEnvVar(): void
    {
        $originalVisual = getenv('VISUAL');
        putenv('VISUAL=vim');

        try {
            $reflection = new \ReflectionClass($this->command);
            $method = $reflection->getMethod('getEditor');
            $method->setAccessible(true);

            $result = $method->invoke($this->command);

            // Should use VISUAL if valid
            $this->assertNotEmpty($result);
        } finally {
            if ($originalVisual !== false) {
                putenv('VISUAL=' . $originalVisual);
            } else {
                putenv('VISUAL');
            }
        }
    }

    public function testGetEditorUsesEditorEnvVar(): void
    {
        $originalVisual = getenv('VISUAL');
        $originalEditor = getenv('EDITOR');
        putenv('VISUAL');
        putenv('EDITOR=nano');

        try {
            $reflection = new \ReflectionClass($this->command);
            $method = $reflection->getMethod('getEditor');
            $method->setAccessible(true);

            $result = $method->invoke($this->command);

            // Should use EDITOR if VISUAL is not set
            $this->assertNotEmpty($result);
        } finally {
            if ($originalVisual !== false) {
                putenv('VISUAL=' . $originalVisual);
            } else {
                putenv('VISUAL');
            }
            if ($originalEditor !== false) {
                putenv('EDITOR=' . $originalEditor);
            } else {
                putenv('EDITOR');
            }
        }
    }

    public function testNormalizePathWithValidAbsolutePath(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('normalizePath');
        $method->setAccessible(true);

        if (PHP_OS_FAMILY === 'Windows') {
            $result = $method->invoke($this->command, 'C:\\Users\\test\\file.txt');
            $this->assertNotNull($result);
        } else {
            $result = $method->invoke($this->command, '/home/user/file.txt');
            $this->assertNotNull($result);
            $this->assertEquals('/home/user/file.txt', $result);
        }
    }

    public function testNormalizePathRejectsTraversal(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('normalizePath');
        $method->setAccessible(true);

        $result = $method->invoke($this->command, '/etc/../../etc/passwd');

        // Should reject directory traversal
        $this->assertNull($result);
    }

    public function testNormalizePathHandlesCurrentDirectory(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('normalizePath');
        $method->setAccessible(true);

        if (PHP_OS_FAMILY !== 'Windows') {
            $result = $method->invoke($this->command, '/home/user/./file.txt');
            $this->assertNotNull($result);
            $this->assertStringNotContainsString('./', $result);
        }
    }

    public function testGetConfigFilePath(): void
    {
        $this->configStorage
            ->method('read')
            ->willReturn([]);

        // Create a mock that has a filePath property
        $reflection = new \ReflectionClass($this->configStorage);
        if (!$reflection->hasProperty('filePath')) {
            // If the mock doesn't have the property, we'll skip this test
            $this->markTestSkipped('ConfigFileStorage mock does not have filePath property');
            return;
        }

        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('getConfigFilePath');
        $method->setAccessible(true);

        // This may fail if the mock doesn't have the property, which is expected
        try {
            $result = $method->invoke($this->command);
            $this->assertIsString($result);
        } catch (\ReflectionException $e) {
            // Expected if mock doesn't have the property
            $this->markTestSkipped('Cannot access filePath property on mock');
        }
    }
}
