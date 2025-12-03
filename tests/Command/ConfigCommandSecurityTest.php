<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\ConfigCommand;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;

class ConfigCommandSecurityTest extends TestCase
{
    private ConfigFileStorageInterface&MockObject $configStorage;
    private ConfigCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->configStorage = $this->createMock(ConfigFileStorageInterface::class);

        $this->command = new ConfigCommand($this->configStorage);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    /**
     * Test that malicious EDITOR environment variable is rejected.
     */
    public function testMaliciousEditorEnvironmentVariable(): void
    {
        $maliciousEditors = [
            'vim; rm -rf /',
            'vim | cat /etc/passwd',
            'vim && echo "injected"',
            'vim `whoami`',
            'vim $(ls)',
            '../bin/vim',
            '/bin/vim; rm',
        ];

        foreach ($maliciousEditors as $maliciousEditor) {
            $originalEditor = getenv('EDITOR');
            $originalVisual = getenv('VISUAL');
            putenv('EDITOR=' . $maliciousEditor);
            putenv('VISUAL=' . $maliciousEditor);

            try {
                // The editor validation should reject malicious editors
                $reflection = new \ReflectionClass($this->command);
                $method = $reflection->getMethod('validateEditorCommand');
                $method->setAccessible(true);
                $result = $method->invoke($this->command, $maliciousEditor);
                $this->assertNull($result, "Failed to reject malicious editor: {$maliciousEditor}");
            } finally {
                if ($originalEditor !== false) {
                    putenv('EDITOR=' . $originalEditor);
                } else {
                    putenv('EDITOR');
                }
                if ($originalVisual !== false) {
                    putenv('VISUAL=' . $originalVisual);
                } else {
                    putenv('VISUAL');
                }
            }
        }
    }

    /**
     * Test that valid editor commands are accepted.
     */
    public function testValidEditorCommands(): void
    {
        $validEditors = ['vim', 'nano', 'vi', 'emacs'];

        foreach ($validEditors as $validEditor) {
            $reflection = new \ReflectionClass($this->command);
            $method = $reflection->getMethod('validateEditorCommand');
            $method->setAccessible(true);
            $result = $method->invoke($this->command, $validEditor);
            $this->assertEquals($validEditor, $result, "Failed to accept valid editor: {$validEditor}");
        }
    }
}
