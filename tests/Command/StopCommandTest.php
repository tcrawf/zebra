<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\StopCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class StopCommandTest extends TestCase
{
    private TrackInterface&MockObject $track;
    private StopCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->track = $this->createMock(TrackInterface::class);

        $this->command = new StopCommand($this->track);

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

    public function testSuccessfulStop(): void
    {
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->track
            ->expects($this->once())
            ->method('stop')
            ->with(null)
            ->willReturn($frame);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame stopped successfully', $output);
        $this->assertStringContainsString('UUID: ' . $frame->uuid, $output);
        $this->assertStringContainsString('Activity: Test Activity', $output);
        $this->assertStringContainsString('Duration: 02:00:00', $output);
        $this->assertStringContainsString('Description: Test description', $output);
    }

    public function testStopWithAtOption(): void
    {
        $startTime = Carbon::now()->utc()->subHours(3);
        $stopTime = Carbon::now()->utc()->subHour();
        $customStopTime = Carbon::parse('2024-01-15T14:30:00Z');
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            ''
        );

        $this->track
            ->expects($this->once())
            ->method('stop')
            ->with($this->callback(function ($time) use ($customStopTime) {
                return $time instanceof Carbon && $time->equalTo($customStopTime);
            }))
            ->willReturn($frame);

        $this->commandTester->execute(['--at' => '2024-01-15T14:30:00Z']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame stopped successfully', $output);
        $this->assertStringNotContainsString('Description:', $output);
    }

    public function testStopWithDurationFormatting(): void
    {
        $startTime = Carbon::now()->utc()->subHours(1)->subMinutes(30)->subSeconds(45);
        $stopTime = Carbon::now()->utc();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            ''
        );

        $this->track
            ->expects($this->once())
            ->method('stop')
            ->with(null)
            ->willReturn($frame);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Duration: 01:30:45', $output);
        $this->assertStringNotContainsString('Description:', $output);
    }

    public function testStopWithNoDurationShowsNA(): void
    {
        $startTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            null,
            $this->activity,
            false,
            $this->role,
            ''
        );

        $this->track
            ->expects($this->once())
            ->method('stop')
            ->with(null)
            ->willReturn($frame);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Duration: N/A', $output);
        $this->assertStringNotContainsString('Description:', $output);
    }

    public function testStopWithNoFrameStartedException(): void
    {
        $this->track
            ->expects($this->once())
            ->method('stop')
            ->with(null)
            ->willThrowException(new NoFrameStartedException('No frame is currently started'));

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No frame is currently started', $output);
    }

    public function testStopWithInvalidTimeException(): void
    {
        $this->track
            ->expects($this->once())
            ->method('stop')
            ->with(null)
            ->willThrowException(new InvalidTimeException('Stop time must be after start time'));

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Stop time must be after start time', $output);
    }

    public function testStopWithGeneralException(): void
    {
        $this->track
            ->expects($this->once())
            ->method('stop')
            ->with(null)
            ->willThrowException(new \RuntimeException('Unexpected error occurred'));

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('An error occurred: Unexpected error occurred', $output);
    }
}
