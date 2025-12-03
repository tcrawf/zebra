<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\CancelCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class CancelCommandTest extends TestCase
{
    private TrackInterface&MockObject $track;
    private CancelCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->track = $this->createMock(TrackInterface::class);

        $this->command = new CancelCommand($this->track);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity = new Activity(
            EntityKey::zebra(1),
            'Test Activity',
            'Description',
            EntityKey::zebra(100)
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    public function testSuccessfulCancel(): void
    {
        $startTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->track
            ->expects($this->once())
            ->method('cancel')
            ->willReturn($frame);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame cancelled successfully', $output);
        $this->assertStringContainsString('UUID: ' . $frame->uuid, $output);
        $this->assertStringContainsString('Activity: Test Activity', $output);
    }

    public function testCancelWithNoFrameStartedException(): void
    {
        $this->track
            ->expects($this->once())
            ->method('cancel')
            ->willThrowException(new NoFrameStartedException('No frame is currently started'));

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No frame is currently started', $output);
    }

    public function testCancelWithGeneralException(): void
    {
        $this->track
            ->expects($this->once())
            ->method('cancel')
            ->willThrowException(new \RuntimeException('Unexpected error occurred'));

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('An error occurred: Unexpected error occurred', $output);
    }
}
