<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Frame;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Config\ConfigFileStorage;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Exception\FrameAlreadyStartedException;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameFactory;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Track\Track;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class TrackTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $testHomeDir;
    private FrameRepositoryInterface&MockObject $frameRepository;
    private ConfigFileStorage $config;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private Track $track;
    private Activity $activity;
    private RoleInterface $role;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('test');
        $this->testHomeDir = $this->root->url();
        putenv('HOME=' . $this->testHomeDir);

        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->config = new ConfigFileStorage();
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $timezoneFormatter = new TimezoneFormatter();
        $this->track = new Track(
            $this->frameRepository,
            $this->config,
            $this->projectRepository,
            $this->userRepository,
            $timezoneFormatter
        );

        $this->activity = new Activity(EntityKey::zebra(1), 'Test Activity', 'Description', EntityKey::zebra(100));
        $this->role = new Role(1, null, 'Developer', 'Full Developer', 'employee', 'active');
    }

    protected function tearDown(): void
    {
        putenv('HOME');
    }

    /**
     * Get the system timezone.
     * Uses the same logic as Track::getSystemTimezone().
     *
     * @return string The timezone identifier
     */
    private function getSystemTimezone(): string
    {
        // Try TZ environment variable (cross-platform, PHP-native)
        $tz = getenv('TZ');
        if ($tz !== false && $tz !== '' && $this->isValidTimezone($tz)) {
            return $tz;
        }

        // Fallback to PHP's default timezone
        return date_default_timezone_get();
    }

    /**
     * Check if a timezone identifier is valid.
     *
     * @param string $timezone The timezone identifier to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function testStartWithDefaultTime(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) {
                return $frame->activity->entityKey->id === $this->activity->entityKey->id
                    && $frame->isActive()
                    && $frame->description === '';
            }));

        $frame = $this->track->start($this->activity);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertTrue($frame->isActive());
        $this->assertEquals($this->activity->entityKey->id, $frame->activity->entityKey->id);
    }

    public function testStartWithDescription(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) {
                return $frame->description === 'Test description';
            }));

        $frame = $this->track->start($this->activity, 'Test description');

        $this->assertEquals('Test description', $frame->description);
    }

    public function testStartWithCustomStartTime(): void
    {
        $startTime = Carbon::now()->subHour()->utc();

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) use ($startTime) {
                return $frame->startTime->timestamp === $startTime->timestamp;
            }));

        $frame = $this->track->start($this->activity, null, $startTime);

        $this->assertEquals($startTime->timestamp, $frame->startTime->timestamp);
    }

    public function testStartThrowsExceptionWhenAlreadyStarted(): void
    {
        $existingFrame = FrameFactory::create(
            Carbon::now()->subHour(),
            null,
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($existingFrame);

        try {
            $this->track->start($this->activity);
            $this->fail('Expected FrameAlreadyStartedException was not thrown');
        } catch (FrameAlreadyStartedException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('A frame is already started', $message);
            $this->assertStringContainsString('Current frame:', $message);
            $this->assertStringContainsString('UUID=' . $existingFrame->uuid, $message);
            // Start time should be in system timezone (after UUID)
            // Get system timezone (same logic as Track class)
            $systemTimezone = $this->getSystemTimezone();
            $startTimeLocal = $existingFrame->startTime->copy()->setTimezone($systemTimezone);
            $this->assertStringContainsString('Start=' . $startTimeLocal->toIso8601String(), $message);
            $this->assertStringContainsString('Activity=' . $existingFrame->activity->name, $message);
            $this->assertStringContainsString('Role=' . $existingFrame->role->name, $message);
            // Verify the order: UUID comes before Start in "Current frame:" section
            $currentFramePos = strpos($message, 'Current frame:');
            $this->assertNotFalse($currentFramePos);
            // Extract the "Current frame:" section to avoid matching "Start=" in "starting"
            $currentFrameSection = substr($message, $currentFramePos);
            // Search for UUID and Start in the extracted section
            $uuidPos = strpos($currentFrameSection, 'UUID=' . $existingFrame->uuid);
            $startPos = strpos($currentFrameSection, ', Start=');
            $this->assertNotFalse($uuidPos, 'UUID should be found in the message');
            $this->assertNotFalse($startPos, 'Start should be found in the message');
            // UUID should come before Start (uuidPos should be less than startPos)
            // In the substring, UUID should appear before Start
            $this->assertLessThan($startPos, $uuidPos, sprintf(
                'UUID should come before Start. UUID position: %d, Start position: %d. Message: %s',
                $uuidPos,
                $startPos,
                $currentFrameSection
            ));
        }
    }

    public function testStartThrowsExceptionWhenStartTimeInFuture(): void
    {
        $futureTime = Carbon::now()->addHour()->utc();

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->never())
            ->method('all');

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUserDefaultRole');

        $this->expectException(InvalidTimeException::class);
        $this->expectExceptionMessage('Cannot start a frame in the future');

        $this->track->start($this->activity, null, $futureTime);
    }

    public function testStartThrowsExceptionWhenStartTimeBeforePreviousFrameEnds(): void
    {
        $previousFrame = FrameFactory::create(
            Carbon::now()->subHours(3),
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$previousFrame]);

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUserDefaultRole');

        $this->expectException(InvalidTimeException::class);
        $this->expectExceptionMessage('Cannot start a frame before the previous frame ends');

        $this->track->start($this->activity, null, Carbon::now()->subHours(2)->utc(), true);
    }

    public function testStartWithGapFalseUsesPreviousFrameStopTime(): void
    {
        $previousStopTime = Carbon::now()->subHour()->utc();
        $previousFrame = FrameFactory::create(
            Carbon::now()->subHours(3),
            $previousStopTime,
            $this->activity,
            false,
            $this->role
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$previousFrame]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) use ($previousStopTime) {
                return $frame->startTime->timestamp === $previousStopTime->timestamp;
            }));

        $frame = $this->track->start($this->activity, null, null, false);

        $this->assertEquals($previousStopTime->timestamp, $frame->startTime->timestamp);
    }

    public function testStartThrowsExceptionWhenNoDefaultRole(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No default role found');

        $this->track->start($this->activity);
    }

    public function testStopWithDefaultTime(): void
    {
        $currentFrame = FrameFactory::create(
            Carbon::now()->subHour(),
            null,
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $completedFrame = FrameFactory::create(
            $currentFrame->startTime,
            Carbon::now(),
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('completeCurrent')
            ->with($this->callback(function ($stopTime) {
                // Should be a Carbon object representing current time (within 2 seconds)
                return $stopTime instanceof Carbon
                    && abs($stopTime->timestamp - Carbon::now()->timestamp) < 2;
            }))
            ->willReturn($completedFrame);

        $frame = $this->track->stop();

        $this->assertFalse($frame->isActive());
        $this->assertNotNull($frame->stopTime);
    }

    public function testStopWithCustomTime(): void
    {
        $currentFrame = FrameFactory::create(
            Carbon::now()->subHours(2),
            null,
            $this->activity,
            false,
            $this->role
        );

        $stopTime = Carbon::now()->subHour()->utc();

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $completedFrame = FrameFactory::create(
            $currentFrame->startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('completeCurrent')
            ->with($stopTime)
            ->willReturn($completedFrame);

        $frame = $this->track->stop($stopTime);

        $this->assertEquals($stopTime->timestamp, $frame->stopTime->timestamp);
    }

    public function testStopThrowsExceptionWhenNoFrameStarted(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->expectException(NoFrameStartedException::class);
        $this->expectExceptionMessage('No frame is started');

        $this->track->stop();
    }

    public function testStopThrowsExceptionWhenStopTimeInFuture(): void
    {
        $currentFrame = FrameFactory::create(
            Carbon::now()->subHour(),
            null,
            $this->activity,
            false,
            $this->role
        );

        $futureTime = Carbon::now()->addHour()->utc();

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $this->frameRepository
            ->expects($this->never())
            ->method('completeCurrent');

        $this->expectException(InvalidTimeException::class);
        $this->expectExceptionMessage('Cannot stop a frame in the future');

        $this->track->stop($futureTime);
    }

    public function testStopThrowsExceptionWhenStopTimeBeforeStartTime(): void
    {
        $startTime = Carbon::now()->subHour()->utc();
        $currentFrame = FrameFactory::create(
            $startTime,
            null,
            $this->activity,
            false,
            $this->role
        );

        $stopTime = $startTime->copy()->subHour();

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $this->frameRepository
            ->expects($this->never())
            ->method('completeCurrent');

        $this->expectException(InvalidTimeException::class);
        $this->expectExceptionMessage('Cannot stop a frame before it starts');

        $this->track->stop($stopTime);
    }

    public function testAdd(): void
    {
        $fromTime = Carbon::now()->subHours(2)->utc();
        $toTime = Carbon::now()->subHour()->utc();

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Frame $frame) use ($fromTime, $toTime) {
                return $frame->startTime->timestamp === $fromTime->timestamp
                    && $frame->stopTime !== null
                    && $frame->stopTime->timestamp === $toTime->timestamp
                    && !$frame->isActive();
            }));

        $frame = $this->track->add($this->activity, $fromTime, $toTime, 'Test description');

        $this->assertFalse($frame->isActive());
        $this->assertEquals($fromTime->timestamp, $frame->startTime->timestamp);
        $this->assertEquals($toTime->timestamp, $frame->stopTime->timestamp);
        $this->assertEquals('Test description', $frame->description);
    }

    public function testAddThrowsExceptionWhenFromTimeAfterToTime(): void
    {
        $fromTime = Carbon::now()->subHour()->utc();
        $toTime = Carbon::now()->subHours(2)->utc();

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUserDefaultRole');

        $this->frameRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(InvalidTimeException::class);
        $this->expectExceptionMessage('Cannot add a frame where start time is after stop time');

        $this->track->add($this->activity, $fromTime, $toTime);
    }

    public function testAddThrowsExceptionWhenNoDefaultRole(): void
    {
        $fromTime = Carbon::now()->subHours(2)->utc();
        $toTime = Carbon::now()->subHour()->utc();

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->never())
            ->method('save');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No default role found');

        $this->track->add($this->activity, $fromTime, $toTime);
    }

    public function testCancel(): void
    {
        $currentFrame = FrameFactory::create(
            Carbon::now()->subHour(),
            null,
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $this->frameRepository
            ->expects($this->once())
            ->method('clearCurrent');

        $frame = $this->track->cancel();

        $this->assertEquals($currentFrame->uuid, $frame->uuid);
    }

    public function testCancelThrowsExceptionWhenNoFrameStarted(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->never())
            ->method('clearCurrent');

        $this->expectException(NoFrameStartedException::class);
        $this->expectExceptionMessage('No frame is started');

        $this->track->cancel();
    }

    public function testIsStartedReturnsTrueWhenFrameExists(): void
    {
        $currentFrame = FrameFactory::create(
            Carbon::now()->subHour(),
            null,
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $this->assertTrue($this->track->isStarted());
    }

    public function testIsStartedReturnsFalseWhenNoFrame(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->assertFalse($this->track->isStarted());
    }

    public function testGetCurrentReturnsFrameWhenExists(): void
    {
        $currentFrame = FrameFactory::create(
            Carbon::now()->subHour(),
            null,
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $frame = $this->track->getCurrent();

        $this->assertNotNull($frame);
        $this->assertEquals($currentFrame->uuid, $frame->uuid);
    }

    public function testGetCurrentReturnsNullWhenNoFrame(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->assertNull($this->track->getCurrent());
    }

    public function testStartWithGapFalseWhenNoPreviousFrames(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) {
                // Should use current time when no previous frames exist
                $now = Carbon::now()->utc();
                $diff = abs($frame->startTime->timestamp - $now->timestamp);
                return $diff < 2; // Allow 2 seconds difference
            }));

        $frame = $this->track->start($this->activity, null, null, false);

        $this->assertTrue($frame->isActive());
    }

    public function testStartWithGapFalseWhenPreviousFrameHasNoStopTime(): void
    {
        // This shouldn't happen in practice, but test edge case
        $previousFrame = FrameFactory::create(
            Carbon::now()->subHours(3),
            null, // No stop time (shouldn't be in all() but test edge case)
            $this->activity,
            false,
            $this->role
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$previousFrame]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) {
                // Should use current time when previous frame has no stop time
                $now = Carbon::now()->utc();
                $diff = abs($frame->startTime->timestamp - $now->timestamp);
                return $diff < 2; // Allow 2 seconds difference
            }));

        $frame = $this->track->start($this->activity, null, null, false);

        $this->assertTrue($frame->isActive());
    }

    public function testStartWithGapFalseIgnoresFutureFrames(): void
    {
        // Create a frame that ends in the future (simulating a frame added via add() with future times)
        $futureFrame = FrameFactory::create(
            Carbon::now()->subHours(2)->utc(),
            Carbon::now()->addHour()->utc(), // Ends in the future
            $this->activity,
            false,
            $this->role
        );

        // Create a frame that ends in the past (this should be used)
        $pastStopTime = Carbon::now()->subHour()->utc();
        $pastFrame = FrameFactory::create(
            Carbon::now()->subHours(3)->utc(),
            $pastStopTime,
            $this->activity,
            false,
            $this->role
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        // Return frames with future frame first (most recent start time)
        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$futureFrame, $pastFrame]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) use ($pastStopTime) {
                // Should use the past frame's stop time, not the future frame's
                return $frame->startTime->timestamp === $pastStopTime->timestamp;
            }));

        $frame = $this->track->start($this->activity, null, null, false);

        $this->assertTrue($frame->isActive());
        $this->assertEquals($pastStopTime->timestamp, $frame->startTime->timestamp);
    }

    public function testStartWithGapFalseUsesCurrentTimeWhenAllFramesEndInFuture(): void
    {
        // Create frames that all end in the future
        $futureFrame1 = FrameFactory::create(
            Carbon::now()->subHours(2)->utc(),
            Carbon::now()->addHour()->utc(), // Ends in the future
            $this->activity,
            false,
            $this->role
        );

        $futureFrame2 = FrameFactory::create(
            Carbon::now()->subHours(4)->utc(),
            Carbon::now()->addHours(2)->utc(), // Ends in the future
            $this->activity,
            false,
            $this->role
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->frameRepository
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn(null);

        // Return frames that all end in the future
        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$futureFrame1, $futureFrame2]);

        $this->frameRepository
            ->expects($this->once())
            ->method('saveCurrent')
            ->with($this->callback(function (Frame $frame) {
                // Should use current time when all frames end in the future
                $now = Carbon::now()->utc();
                $diff = abs($frame->startTime->timestamp - $now->timestamp);
                return $diff < 2; // Allow 2 seconds difference
            }));

        $frame = $this->track->start($this->activity, null, null, false);

        $this->assertTrue($frame->isActive());
    }
}
