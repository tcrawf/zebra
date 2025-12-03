<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\Command\EditCommand;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class EditCommandSecurityTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private TimezoneFormatter $timezoneFormatter;
    private ActivityRepositoryInterface&MockObject $activityRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private FrameAutocompletion&MockObject $autocompletion;
    private EditCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->timezoneFormatter = new TimezoneFormatter();
        $this->activityRepository = $this->createMock(ActivityRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->autocompletion = $this->createMock(FrameAutocompletion::class);

        $this->command = new EditCommand(
            $this->frameRepository,
            $this->timezoneFormatter,
            $this->activityRepository,
            $this->userRepository,
            $this->autocompletion
        );

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
                // We can't easily test the actual editor opening, but we can verify
                // that the validation method exists and works
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

    /**
     * Test that editor option with malicious value is rejected.
     */
    public function testMaliciousEditorOption(): void
    {
        $maliciousEditors = [
            'vim; rm -rf /',
            'vim | cat',
            'vim && echo "injected"',
        ];

        foreach ($maliciousEditors as $maliciousEditor) {
            $reflection = new \ReflectionClass($this->command);
            $method = $reflection->getMethod('validateEditorCommand');
            $method->setAccessible(true);
            $result = $method->invoke($this->command, $maliciousEditor);
            $this->assertNull($result, "Failed to reject malicious editor option: {$maliciousEditor}");
        }
    }
}
