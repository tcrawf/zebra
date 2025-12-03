<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\Command\RestartCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Exception\FrameAlreadyStartedException;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class RestartCommandTest extends TestCase
{
    private TrackInterface&MockObject $track;
    private FrameRepositoryInterface&MockObject $frameRepository;
    private FrameAutocompletion&MockObject $autocompletion;
    private TimezoneFormatter $timezoneFormatter;
    private RestartCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->track = $this->createMock(TrackInterface::class);
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->autocompletion = $this->createMock(FrameAutocompletion::class);
        $this->timezoneFormatter = new TimezoneFormatter();

        $this->command = new RestartCommand(
            $this->track,
            $this->frameRepository,
            $this->autocompletion,
            $this->timezoneFormatter
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity = new Activity(
            EntityKey::zebra(1),
            'Test Activity',
            'Description',
            EntityKey::zebra(100),
            'test-alias'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    public function testRestartWithFrameOptionAndUuid(): void
    {
        $frameUuid = Uuid::random()->getHex();
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $restartedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Test description',
                null,
                true, // gap by default
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->execute(['--frame' => $frameUuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
        $this->assertStringContainsString('UUID: ' . $restartedFrame->uuid, $output);
        $this->assertStringContainsString('Activity: Test Activity', $output);
        $this->assertStringContainsString('Description: Test description', $output);
    }

    public function testRestartWithFrameOptionAndNoGap(): void
    {
        $frameUuid = Uuid::random()->getHex();
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $restartedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Test description',
                null,
                false, // no gap
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->execute(['--frame' => $frameUuid, '--no-gap' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
    }

    public function testRestartWithFrameOptionAndGap(): void
    {
        $frameUuid = Uuid::random()->getHex();
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $restartedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Test description',
                null,
                true, // gap (default)
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->execute(['--frame' => $frameUuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
    }

    public function testRestartWithArgumentBackwardCompatibility(): void
    {
        $frameUuid = Uuid::random()->getHex();
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $restartedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Test description',
                null,
                true,
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->execute(['frame' => $frameUuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
    }

    public function testRestartWithNegativeIndex(): void
    {
        $startTime1 = Carbon::now()->utc()->subHours(3);
        $stopTime1 = Carbon::now()->utc()->subHours(2);
        $frame1 = new Frame(
            Uuid::random(),
            $startTime1,
            $stopTime1,
            $this->activity,
            false,
            $this->role,
            'First frame'
        );

        $startTime2 = Carbon::now()->utc()->subHours(2);
        $stopTime2 = Carbon::now()->utc()->subHour();
        $frame2 = new Frame(
            Uuid::random(),
            $startTime2,
            $stopTime2,
            $this->activity,
            false,
            $this->role,
            'Second frame'
        );

        $restartedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc(),
            null,
            $this->activity,
            false,
            $this->role,
            'Second frame'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$frame1, $frame2]);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Second frame',
                null,
                true,
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->execute(['frame' => '-1']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
    }

    public function testRestartWithAtOption(): void
    {
        $frameUuid = Uuid::random()->getHex();
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $customStartTime = Carbon::parse('2024-01-15T14:30:00Z');
        $restartedFrame = new Frame(
            Uuid::random(),
            $customStartTime,
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Test description',
                $this->callback(function ($time) use ($customStartTime) {
                    return $time instanceof Carbon && $time->equalTo($customStartTime);
                }),
                true,
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->execute([
            '--frame' => $frameUuid,
            '--at' => '2024-01-15T14:30:00Z'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
    }

    public function testRestartWithFrameNotFound(): void
    {
        // Use a UUID that definitely won't match numeric pattern (contains letters)
        $frameUuid = 'a1b2c3d4';

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn(null);

        $this->commandTester->execute(['--frame' => $frameUuid]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Frame '{$frameUuid}' not found", $output);
    }

    public function testRestartWithFrameAlreadyStartedException(): void
    {
        // Use a UUID that definitely won't match numeric pattern (contains letters)
        $frameUuid = 'a1b2c3d4';
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->willThrowException(new FrameAlreadyStartedException('A frame is already started'));

        $this->commandTester->execute(['--frame' => $frameUuid]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('A frame is already started', $output);
    }

    public function testRestartWithInvalidTimeException(): void
    {
        // Use a UUID that definitely won't match numeric pattern (contains letters)
        $frameUuid = 'a1b2c3d4';
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->willThrowException(new InvalidTimeException('Start time must be after previous frame'));

        $this->commandTester->execute(['--frame' => $frameUuid]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Start time must be after previous frame', $output);
    }

    public function testRestartWithFrameOptionEmptyRequiresInteractive(): void
    {
        // When --frame is provided without value in non-interactive mode, it should fail
        $this->commandTester->setInputs([]);
        $this->commandTester->execute(['--frame' => ''], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame selection requires interactive mode', $output);
    }

    public function testRestartWithFrameOptionWithoutValueRequiresInteractive(): void
    {
        // When --frame is provided without any value (not even empty string) in non-interactive mode, it should fail
        $this->commandTester->setInputs([]);
        // Simulate --frame without value by passing null
        $this->commandTester->execute(['--frame' => null], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame selection requires interactive mode', $output);
    }

    public function testRestartWithStopOption(): void
    {
        $frameUuid = Uuid::random()->getHex();
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $stoppedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc()->subHour(),
            Carbon::now()->utc(),
            $this->activity,
            false,
            $this->role,
            'Current frame'
        );

        $restartedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($frameUuid)
            ->willReturn($frame);

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(true);

        $this->track
            ->expects($this->once())
            ->method('stop')
            ->willReturn($stoppedFrame);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Test description',
                null,
                true,
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->execute(['--frame' => $frameUuid, '--stop' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
    }

    public function testRestartWithFrameOptionWithoutValueChecksForRunningFrame(): void
    {
        // When --frame is provided without value and a frame is already running, it should check first
        $currentFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc()->subHour(),
            null,
            $this->activity,
            false,
            $this->role,
            'Current frame'
        );

        $this->track
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(true);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $this->commandTester->execute(['--frame' => null], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('A frame is already started', $output);
        $this->assertStringContainsString('Current frame: UUID=' . $currentFrame->uuid, $output);
    }

    public function testRestartWithFrameOptionWithoutValueAndStopOption(): void
    {
        // When --frame is provided without value and --stop is provided, it should stop first
        $currentFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc()->subHour(),
            Carbon::now()->utc(),
            $this->activity,
            false,
            $this->role,
            'Current frame'
        );

        $frameUuid = Uuid::random()->getHex();
        $startTime = Carbon::now()->utc()->subHours(2);
        $stopTime = Carbon::now()->utc()->subHour();
        $frame = new Frame(
            Uuid::fromHex($frameUuid),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $restartedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->utc(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $todayStart = Carbon::now()->startOfDay()->utc();
        $todayEnd = Carbon::now()->endOfDay()->utc();

        $this->track
            ->expects($this->exactly(3))
            ->method('isStarted')
            ->willReturn(true, false, false);

        $this->track
            ->expects($this->once())
            ->method('stop')
            ->willReturn($currentFrame);

        $this->frameRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($todayStart, $todayEnd)
            ->willReturn([$frame]);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with(
                $this->activity,
                'Test description',
                null,
                true,
                false, // isIndividual
                $this->role
            )
            ->willReturn($restartedFrame);

        $this->commandTester->setInputs(['0']);
        $this->commandTester->execute(['--frame' => null, '--stop' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame restarted successfully', $output);
    }
}
