<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Frame;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameFactory;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;

class FrameFactoryTest extends TestCase
{
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $this->activity = new Activity($activityEntityKey, 'Test Activity', 'Description', $projectEntityKey);
        $this->role = new Role(1, null, 'Developer');
    }

    public function testCreate(): void
    {
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = FrameFactory::create($startTime, $stopTime, $this->activity, false, $this->role, 'Description');

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertNotNull($frame->uuid);
        $this->assertEquals($startTime->timestamp, $frame->getStartTimestamp());
        $this->assertEquals($stopTime->timestamp, $frame->getStopTimestamp());
    }

    public function testCreateWithUuid(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $frame = FrameFactory::create($startTime, null, $this->activity, false, $this->role, '', null, $uuid);

        $this->assertEquals($uuid->getHex(), $frame->uuid);
    }

    public function testCreateGeneratesRandomUuid(): void
    {
        $frame1 = FrameFactory::create(Carbon::now(), null, $this->activity, false, $this->role);
        $frame2 = FrameFactory::create(Carbon::now(), null, $this->activity, false, $this->role);

        $this->assertNotEquals($frame1->uuid, $frame2->uuid);
    }

    public function testFromArray(): void
    {
        $uuid = Uuid::random();
        $startTimestamp = time() - 3600;
        $stopTimestamp = time();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => $startTimestamp,
            'stop' => $stopTimestamp,
            'activity' => $this->activity,
            false,

            'isIndividual' => false,
            'role' => $this->role,
            'desc' => 'Test description',
            'updatedAt' => $startTimestamp
        ];

        $frame = FrameFactory::fromArray($data);

        $this->assertEquals($uuid->getHex(), $frame->uuid);
        $this->assertEquals($startTimestamp, $frame->getStartTimestamp());
        $this->assertEquals($stopTimestamp, $frame->getStopTimestamp());
        $this->assertEquals('Test description', $frame->description);
    }

    public function testFromArrayWithUuidString(): void
    {
        $uuidString = '550e8400';
        $data = [
            'uuid' => $uuidString,
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => $this->activity,
            false,

            'isIndividual' => false,
            'role' => $this->role,
            'desc' => '',
            'updatedAt' => time()
        ];

        $frame = FrameFactory::fromArray($data);

        $this->assertEquals($uuidString, $frame->uuid);
    }

    public function testFromArrayWithRoleArray(): void
    {
        $uuid = Uuid::random();
        $startTimestamp = time() - 3600;
        $stopTimestamp = time();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => $startTimestamp,
            'stop' => $stopTimestamp,
            'activity' => [
                'key' => [
                    'source' => $this->activity->entityKey->source->value,
                    'id' => $this->activity->entityKey->toString(),
                ],
                'name' => $this->activity->name,
                'desc' => $this->activity->description,
                'project' => [
                    'source' => $this->activity->projectEntityKey->source->value,
                    'id' => $this->activity->projectEntityKey->toString(),
                ],
                'alias' => $this->activity->alias,
            ],
            'isIndividual' => false,
            'role' => [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ],
            'desc' => 'Test description',
            'updatedAt' => $startTimestamp
        ];

        $frame = FrameFactory::fromArray($data);

        $this->assertEquals($uuid->getHex(), $frame->uuid);
        $this->assertEquals($this->role->id, $frame->role->id);
        $this->assertEquals($this->role->name, $frame->role->name);
    }

    public function testWithStopTime(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHours(2);
        $frame = new Frame(
            $uuid,
            $startTime,
            null,
            $this->activity,
            false,
            $this->role,
            'Active frame'
        );

        $stopTime = Carbon::now();
        $completedFrame = FrameFactory::withStopTime($frame, $stopTime);

        $this->assertNotSame($frame, $completedFrame);
        $this->assertFalse($completedFrame->isActive());
        $this->assertEquals($stopTime->timestamp, $completedFrame->getStopTimestamp());
        $this->assertEquals('Active frame', $completedFrame->description);
        $this->assertEquals($frame->uuid, $completedFrame->uuid);
    }

    public function testWithStopTimeUsingInt(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $frame = new Frame(
            $uuid,
            $startTime,
            null,
            $this->activity,
            false,
            $this->role
        );

        $stopTimestamp = time();
        $completedFrame = FrameFactory::withStopTime($frame, $stopTimestamp);

        $this->assertEquals($stopTimestamp, $completedFrame->getStopTimestamp());
    }

    public function testWithStopTimeUsingString(): void
    {
        $uuid = Uuid::random();
        $timezoneFormatter = new TimezoneFormatter();
        $startTime = $timezoneFormatter->parseLocalToUtc('2024-01-01 10:00:00');
        $frame = new Frame(
            $uuid,
            $startTime,
            null,
            $this->activity,
            false,
            $this->role
        );

        $stopTime = '2024-01-01 12:00:00';
        $completedFrame = FrameFactory::withStopTime($frame, $stopTime);

        $expectedTimestamp = $timezoneFormatter->parseLocalToUtc($stopTime)->timestamp;
        $this->assertEquals($expectedTimestamp, $completedFrame->getStopTimestamp());
    }

    public function testCreateWithIndividualFrame(): void
    {
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = FrameFactory::create($startTime, $stopTime, $this->activity, true, null, 'Individual action');

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertTrue($frame->isIndividual);
        $this->assertNull($frame->role);
    }

    public function testFromArrayWithIndividualFrame(): void
    {
        $uuid = Uuid::random();
        $startTimestamp = time() - 3600;
        $stopTimestamp = time();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => $startTimestamp,
            'stop' => $stopTimestamp,
            'activity' => $this->activity,
            false,

            'isIndividual' => true,
            'role' => null,
            'desc' => 'Individual description',
            'updatedAt' => $startTimestamp
        ];

        $frame = FrameFactory::fromArray($data);

        $this->assertEquals($uuid->getHex(), $frame->uuid);
        $this->assertTrue($frame->isIndividual);
        $this->assertNull($frame->role);
    }

    public function testFromArrayThrowsExceptionIfIsIndividualMissing(): void
    {
        $uuid = Uuid::random();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => $this->activity,
            false,

            'role' => $this->role,
            'desc' => 'Test',
            'updatedAt' => time()
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid array format: 'isIndividual' key is required");

        FrameFactory::fromArray($data);
    }

    public function testFromArrayThrowsExceptionIfUuidMissing(): void
    {
        $data = [
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => $this->activity,
            'isIndividual' => false,
            'role' => $this->role,
            'desc' => 'Test',
            'updatedAt' => time()
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid array format: 'uuid' key is required");

        FrameFactory::fromArray($data);
    }

    public function testFromArrayThrowsExceptionIfActivityMissingKey(): void
    {
        $uuid = Uuid::random();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => [
                'name' => 'Test Activity',
                // Missing 'key' and 'project'
            ],
            'isIndividual' => false,
            'role' => $this->role,
            'desc' => 'Test',
            'updatedAt' => time()
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid array format: 'activity' must have 'key' and 'project' keys");

        FrameFactory::fromArray($data);
    }

    public function testFromArrayWithUpdatedAt(): void
    {
        $uuid = Uuid::random();
        $startTimestamp = time() - 3600;
        $stopTimestamp = time();
        $updatedAtTimestamp = time() - 1800;
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => $startTimestamp,
            'stop' => $stopTimestamp,
            'activity' => $this->activity,
            'isIndividual' => false,
            'role' => $this->role,
            'desc' => 'Test description',
            'updatedAt' => $updatedAtTimestamp
        ];

        $frame = FrameFactory::fromArray($data);

        $this->assertEquals($updatedAtTimestamp, $frame->getUpdatedAtTimestamp());
    }

    public function testCreateWithIntStartTime(): void
    {
        $startTimestamp = time() - 3600;
        $stopTimestamp = time();
        $frame = FrameFactory::create($startTimestamp, $stopTimestamp, $this->activity, false, $this->role);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertEquals($startTimestamp, $frame->getStartTimestamp());
        $this->assertEquals($stopTimestamp, $frame->getStopTimestamp());
    }

    public function testCreateWithStringStartTime(): void
    {
        $startTime = '2024-01-01 10:00:00';
        $stopTime = '2024-01-01 11:00:00';
        $frame = FrameFactory::create($startTime, $stopTime, $this->activity, false, $this->role);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertNotNull($frame->getStartTimestamp());
        $this->assertNotNull($frame->getStopTimestamp());
    }

    public function testCreateWithUpdatedAt(): void
    {
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $updatedAt = Carbon::now()->subMinutes(30);
        $frame = FrameFactory::create($startTime, $stopTime, $this->activity, false, $this->role, '', $updatedAt);

        $this->assertEquals($updatedAt->timestamp, $frame->getUpdatedAtTimestamp());
    }

    public function testFromArrayWithNullRole(): void
    {
        $uuid = Uuid::random();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => $this->activity,
            'isIndividual' => true,
            'role' => null,
            'desc' => 'Test',
            'updatedAt' => time()
        ];

        $frame = FrameFactory::fromArray($data);

        $this->assertNull($frame->role);
        $this->assertTrue($frame->isIndividual);
    }

    public function testFromArrayWithRoleInterface(): void
    {
        $uuid = Uuid::random();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => $this->activity,
            'isIndividual' => false,
            'role' => $this->role,
            'desc' => 'Test',
            'updatedAt' => time()
        ];

        $frame = FrameFactory::fromArray($data);

        $this->assertEquals($this->role, $frame->role);
    }

    public function testFromArrayThrowsExceptionIfRoleInvalidType(): void
    {
        $uuid = Uuid::random();
        $data = [
            'uuid' => $uuid->getHex(),
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => $this->activity,
            'isIndividual' => false,
            'role' => 'invalid', // Invalid type
            'desc' => 'Test',
            'updatedAt' => time()
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid array format: 'role' must be null, an array, or RoleInterface");

        FrameFactory::fromArray($data);
    }
}
