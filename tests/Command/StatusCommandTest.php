<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\StatusCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;

class StatusCommandTest extends TestCase
{
    private TrackInterface&MockObject $track;
    private TimezoneFormatter&MockObject $timezoneFormatter;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private StatusCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;
    private Project $project;

    protected function setUp(): void
    {
        $this->track = $this->createMock(TrackInterface::class);
        $this->timezoneFormatter = $this->createMock(TimezoneFormatter::class);
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);

        $this->command = new StatusCommand(
            $this->track,
            $this->timezoneFormatter,
            $this->projectRepository
        );

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
        $this->project = new Project(
            EntityKey::zebra(100),
            'Test Project',
            'Description',
            1,
            [$this->activity]
        );
    }

    public function testNoFrameStarted(): void
    {
        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No project started.', $this->commandTester->getDisplay());
    }

    public function testNoFrameStartedWhenGetCurrentReturnsNull(): void
    {
        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No project started.', $this->commandTester->getDisplay());
    }

    public function testFullStatusOutput(): void
    {
        $startTime = Carbon::now()->utc()->subHours(2)->subMinutes(30)->subSeconds(45);
        $localTime = Carbon::now()->setTimezone('America/New_York');
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with(EntityKey::zebra(100))
            ->willReturn($this->project);

        $this->timezoneFormatter
            ->expects($this->once())
            ->method('toLocal')
            ->with($startTime)
            ->willReturn($localTime);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Test Project', $output);
        $this->assertStringContainsString('Test Activity', $output);
        $this->assertStringContainsString('2h 30m', $output);
        $this->assertStringContainsString('Test description', $output);
    }

    public function testProjectOnlyOption(): void
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with(EntityKey::zebra(100))
            ->willReturn($this->project);

        $this->commandTester->execute(['--project' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = trim($this->commandTester->getDisplay());
        $this->assertEquals('Test Project', $output);
    }

    public function testActivityOnlyOption(): void
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->commandTester->execute(['--activity' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = trim($this->commandTester->getDisplay());
        $this->assertEquals('Test Activity', $output);
    }

    public function testElapsedOnlyOption(): void
    {
        $startTime = Carbon::now()->utc()->subHours(1)->subMinutes(15)->subSeconds(30);
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->commandTester->execute(['--elapsed' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = trim($this->commandTester->getDisplay());
        $this->assertStringContainsString('1h 15m', $output);
    }

    public function testElapsedTimeFormattingHoursMinutesSeconds(): void
    {
        $startTime = Carbon::now()->utc()->subHours(3)->subMinutes(45)->subSeconds(20);
        $localTime = Carbon::now()->setTimezone('America/New_York');
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with(EntityKey::zebra(100))
            ->willReturn($this->project);

        $this->timezoneFormatter
            ->expects($this->once())
            ->method('toLocal')
            ->with($startTime)
            ->willReturn($localTime);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('3h 45m 20s', $output);
    }

    public function testElapsedTimeFormattingMinutesSeconds(): void
    {
        $startTime = Carbon::now()->utc()->subMinutes(30)->subSeconds(15);
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->commandTester->execute(['--elapsed' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = trim($this->commandTester->getDisplay());
        $this->assertStringContainsString('30m 15s', $output);
        $this->assertStringNotContainsString('h', $output);
    }

    public function testElapsedTimeFormattingSecondsOnly(): void
    {
        $startTime = Carbon::now()->utc()->subSeconds(45);
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->commandTester->execute(['--elapsed' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = trim($this->commandTester->getDisplay());
        $this->assertStringContainsString('45s', $output);
        $this->assertStringNotContainsString('m', $output);
        $this->assertStringNotContainsString('h', $output);
    }

    public function testProjectNameFallbackToActivityNameWhenProjectNotFound(): void
    {
        $startTime = Carbon::now()->utc()->subHour();
        $localTime = Carbon::now()->setTimezone('America/New_York');
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with(EntityKey::zebra(100))
            ->willReturn(null);

        $this->timezoneFormatter
            ->expects($this->once())
            ->method('toLocal')
            ->with($startTime)
            ->willReturn($localTime);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Test Activity', $output);
        $this->assertStringNotContainsString('Test Project', $output);
    }

    public function testFullStatusOutputWithoutDescription(): void
    {
        $startTime = Carbon::now()->utc()->subHours(1)->subMinutes(15);
        $localTime = Carbon::now()->setTimezone('America/New_York');
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
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($frame);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with(EntityKey::zebra(100))
            ->willReturn($this->project);

        $this->timezoneFormatter
            ->expects($this->once())
            ->method('toLocal')
            ->with($startTime)
            ->willReturn($localTime);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Test Project', $output);
        $this->assertStringContainsString('Test Activity', $output);
        $this->assertStringContainsString('1h 15m', $output);
        // Verify the output doesn't end with a dash (which would indicate description)
        $this->assertStringNotContainsString(' - ', $output);
    }
}
