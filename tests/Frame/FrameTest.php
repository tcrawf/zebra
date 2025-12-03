<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Frame;

use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;

class FrameTest extends TestCase
{
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->activity = new Activity(EntityKey::zebra(1), 'Test Activity', 'Description', EntityKey::zebra(100));
        $this->role = new Role(1, null, 'Developer');
    }

    public function testConstructorWithAllParameters(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description',
            $startTime
        );

        $this->assertEquals($uuid->getHex(), $frame->uuid);
        $this->assertEquals($startTime->timestamp, $frame->getStartTimestamp());
        $this->assertEquals($stopTime->timestamp, $frame->getStopTimestamp());
        $this->assertEquals($this->activity, $frame->activity);
        $this->assertEquals('Test description', $frame->description);
        $this->assertFalse($frame->isActive());
    }

    public function testConstructorWithActiveFrame(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $frame = new Frame($uuid, $startTime, null, $this->activity, false, $this->role);

        $this->assertTrue($frame->isActive());
        $this->assertNull($frame->stopTime);
        $this->assertNull($frame->getStopTimestamp());
        $this->assertNull($frame->getDuration());
    }

    public function testConstructorWithStopTimeBeforeStartTimeThrowsException(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now();
        $stopTime = Carbon::now()->subHour();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stop time must be equal to or greater than start time');

        new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role);
    }

    public function testGetDuration(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHours(2);
        $stopTime = Carbon::now();
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role);

        $duration = $frame->getDuration();
        $this->assertNotNull($duration);
        $this->assertGreaterThanOrEqual(7200, $duration); // At least 2 hours in seconds
    }

    public function testExtractIssueKeys(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame(
            $uuid,
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'Worked on ABC-123 and XYZ-456 issues'
        );

        $issueKeys = $frame->issueKeys;
        $this->assertCount(2, $issueKeys);
        $this->assertContains('ABC-123', $issueKeys);
        $this->assertContains('XYZ-456', $issueKeys);
    }

    public function testExtractIssueKeysWithVariousFormats(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame(
            $uuid,
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'AA-1 BB-12 CCC-123 DDDD-1234 EEEEE-12345'
        );

        $issueKeys = $frame->issueKeys;
        $this->assertCount(5, $issueKeys);
    }

    public function testExtractIssueKeysNoMatches(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame($uuid, Carbon::now(), null, $this->activity, false, $this->role, 'No issue keys here');

        $issueKeys = $frame->issueKeys;
        $this->assertEmpty($issueKeys);
    }

    public function testToArray(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role, 'Description');

        $array = $frame->toArray();

        $this->assertEquals($uuid->getHex(), $array['uuid']);
        $this->assertEquals($startTime->timestamp, $array['start']);
        $this->assertEquals($stopTime->timestamp, $array['stop']);
        $this->assertIsArray($array['activity']);
        $this->assertIsArray($array['activity']['key']);
        $this->assertEquals($this->activity->entityKey->toString(), $array['activity']['key']['id']);
        $this->assertEquals($this->activity->name, $array['activity']['name']);
        $this->assertEquals($this->activity->description, $array['activity']['desc']);
        $this->assertIsArray($array['activity']['project']);
        $this->assertEquals($this->activity->projectEntityKey->toString(), $array['activity']['project']['id']);
        $this->assertEquals($this->activity->alias, $array['activity']['alias']);
        $this->assertFalse($array['isIndividual']);
        $this->assertIsArray($array['role']);
        $this->assertEquals($this->role->id, $array['role']['id']);
        $this->assertEquals($this->role->name, $array['role']['name']);
        $this->assertEquals('Description', $array['desc']);
        $this->assertArrayHasKey('updatedAt', $array);
    }

    public function testConstructorWithIndividualFrame(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, true, null, 'Individual action');

        $this->assertTrue($frame->isIndividual);
        $this->assertNull($frame->role);
        $this->assertEquals($uuid->getHex(), $frame->uuid);
    }

    public function testConstructorWithIndividualFrameThrowsExceptionIfRoleProvided(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Individual frames cannot have a role');

        new Frame($uuid, $startTime, null, $this->activity, true, $this->role);
    }

    public function testConstructorWithNonIndividualFrameThrowsExceptionIfNoRole(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Frame must have either a role or be marked as individual');

        new Frame($uuid, $startTime, null, $this->activity, false, null);
    }

    public function testToArrayWithIndividualFrame(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, true, null, 'Individual description');

        $array = $frame->toArray();

        $this->assertTrue($array['isIndividual']);
        $this->assertNull($array['role']);
        $this->assertEquals('Individual description', $array['desc']);
    }

    public function testComparisonMethods(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subHours(2);
        $start2 = Carbon::now()->subHour();

        $frame1 = new Frame($uuid1, $start1, null, $this->activity, false, $this->role);
        $frame2 = new Frame($uuid2, $start2, null, $this->activity, false, $this->role);

        $this->assertTrue($frame1->isLessThan($frame2));
        $this->assertTrue($frame1->isLessThanOrEqual($frame2));
        $this->assertFalse($frame1->isGreaterThan($frame2));
        $this->assertFalse($frame1->isGreaterThanOrEqual($frame2));

        $this->assertFalse($frame2->isLessThan($frame1));
        $this->assertTrue($frame2->isGreaterThan($frame1));
    }

    public function testToString(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame($uuid, Carbon::now(), null, $this->activity, false, $this->role, 'Test description');

        $string = (string) $frame;
        $this->assertStringContainsString('Frame(', $string);
        $this->assertStringContainsString($uuid->getHex(), $string);
        $this->assertStringContainsString('Test Activity', $string);
    }

    public function testTimeNormalizationToUtc(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now('America/New_York');
        $frame = new Frame($uuid, $startTime, null, $this->activity, false, $this->role);

        $this->assertEquals('UTC', $frame->startTime->timezone->getName());
    }

    public function testConstructorWithIntTimestamps(): void
    {
        $uuid = Uuid::random();
        $startTimestamp = time() - 3600;
        $stopTimestamp = time();
        $frame = new Frame($uuid, $startTimestamp, $stopTimestamp, $this->activity, false, $this->role);

        $this->assertEquals($startTimestamp, $frame->getStartTimestamp());
        $this->assertEquals($stopTimestamp, $frame->getStopTimestamp());
    }

    public function testConstructorWithStringTimestamps(): void
    {
        $uuid = Uuid::random();
        $timezoneFormatter = new TimezoneFormatter();
        $startTime = '2024-01-01 10:00:00';
        $stopTime = '2024-01-01 12:00:00';
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role);

        $expectedStartTimestamp = $timezoneFormatter->parseLocalToUtc($startTime)->timestamp;
        $expectedStopTimestamp = $timezoneFormatter->parseLocalToUtc($stopTime)->timestamp;
        $this->assertEquals($expectedStartTimestamp, $frame->getStartTimestamp());
        $this->assertEquals($expectedStopTimestamp, $frame->getStopTimestamp());
    }
}
