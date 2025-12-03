<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\Timesheet;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetTest extends TestCase
{
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->activity = new Activity(
            EntityKey::zebra(123),
            'Test Activity',
            'Activity Description',
            EntityKey::zebra(100),
            'activity-123'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    public function testConstructorWithAllParameters(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $frameUuids = ['abc12345', 'def67890'];
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            'Client description',
            2.5,
            $date,
            $this->role,
            false,
            $frameUuids
        );

        $this->assertEquals($uuid->getHex(), $timesheet->uuid);
        $this->assertEquals(100, $timesheet->getProjectId());
        $this->assertEquals($this->activity, $timesheet->activity);
        $this->assertEquals('Test description', $timesheet->description);
        $this->assertEquals('Client description', $timesheet->clientDescription);
        $this->assertEquals(2.5, $timesheet->time);
        $this->assertEquals($date->format('Y-m-d'), $timesheet->date->format('Y-m-d'));
        $this->assertEquals($this->role, $timesheet->role);
        $this->assertFalse($timesheet->individualAction);
        $this->assertEquals($frameUuids, $timesheet->frameUuids);
        $this->assertNull($timesheet->zebraId);
    }

    public function testConstructorWithOptionalParameters(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $zebraId = 42;
        $updatedAt = Carbon::now()->subHour();
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            null,
            true,
            [],
            $zebraId,
            $updatedAt
        );

        $this->assertNull($timesheet->clientDescription);
        $this->assertNull($timesheet->role);
        $this->assertTrue($timesheet->individualAction);
        $this->assertEquals([], $timesheet->frameUuids);
        $this->assertEquals($zebraId, $timesheet->zebraId);
        $this->assertEquals($updatedAt->timestamp, $timesheet->getUpdatedAtTimestamp());
    }

    public function testConstructorWithIndividualAction(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            null,
            true,
            []
        );

        $this->assertNull($timesheet->role);
        $this->assertTrue($timesheet->individualAction);
    }

    public function testConstructorWithRole(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $role = new Role(5, null, 'Manager', 'Manager', 'employee', 'active');
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $role,
            false,
            []
        );

        $this->assertEquals($role, $timesheet->role);
        $this->assertEquals(5, $timesheet->role->id);
        $this->assertFalse($timesheet->individualAction);
    }

    public function testConstructorWithNeitherRoleIdNorIndividualActionThrowsException(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either role must be set or individualAction must be true');

        new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            null,
            false,
            []
        );
    }

    public function testConstructorWithNegativeTimeThrowsException(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Time must be non-negative');

        new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            -1.0,
            $date,
            $this->role,
            false,
            []
        );
    }

    public function testConstructorWithInvalidTimeMultipleThrowsException(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Time must be a multiple of 0.25');

        new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.3,
            $date,
            $this->role,
            false,
            []
        );
    }

    public function testConstructorWithValidTimeMultiples(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();

        // Test various valid multiples of 0.25
        $validTimes = [0.0, 0.25, 0.5, 0.75, 1.0, 1.25, 2.5, 7.75, 8.0];

        foreach ($validTimes as $time) {
            $timesheet = new Timesheet(
                $uuid,
                $this->activity,
                'Test description',
                null,
                $time,
                $date,
                $this->role,
                false,
                []
            );
            $this->assertEquals($time, $timesheet->time);
        }
    }

    public function testConstructorWithInvalidFrameUuidTypeThrowsException(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All frame UUIDs must be strings');

        new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [123, 'valid'] // Mixed types
        );
    }

    public function testConstructorWithLocalActivityThrowsException(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $localActivity = new Activity(
            EntityKey::local(Uuid::random()),
            'Local Activity',
            'Description',
            EntityKey::local(Uuid::random())
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timesheet can only accept Zebra activities');

        new Timesheet(
            $uuid,
            $localActivity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );
    }


    public function testGetDateTimestamp(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15, 12, 0, 0);
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->assertEquals($date->startOfDay()->timestamp, $timesheet->getDateTimestamp());
    }

    public function testGetUpdatedAtTimestamp(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $updatedAt = Carbon::now()->subHours(2);
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [],
            null,
            $updatedAt
        );

        $this->assertEquals($updatedAt->timestamp, $timesheet->getUpdatedAtTimestamp());
    }

    public function testGetUpdatedAtTimestampDefaultsToCurrentTime(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $beforeCreation = Carbon::now()->timestamp;

        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $afterCreation = Carbon::now()->timestamp;
        $updatedAtTimestamp = $timesheet->getUpdatedAtTimestamp();

        $this->assertGreaterThanOrEqual($beforeCreation, $updatedAtTimestamp);
        $this->assertLessThanOrEqual($afterCreation, $updatedAtTimestamp);
    }

    public function testDateIsNormalizedToStartOfDay(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15, 14, 30, 45);
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->assertEquals('2024-01-15', $timesheet->date->format('Y-m-d'));
        $this->assertEquals(0, $timesheet->date->hour);
        $this->assertEquals(0, $timesheet->date->minute);
        $this->assertEquals(0, $timesheet->date->second);
    }

    public function testToArray(): void
    {
        $uuid = Uuid::random();
        $updatedAt = Carbon::now()->subHour();
        $frameUuids = ['abc12345', 'def67890'];
        $role = new Role(5, null, 'Manager', 'Manager', 'employee', 'active');
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            'Client description',
            2.5,
            '2024-01-15',
            $role,
            false,
            $frameUuids,
            42,
            $updatedAt
        );

        $array = $timesheet->toArray();

        $this->assertEquals($uuid->getHex(), $array['uuid']);
        $this->assertEquals(100, $array['projectId']);
        $this->assertIsArray($array['activity']);
        $this->assertEquals('zebra', $array['activity']['key']['source']);
        $this->assertEquals('123', $array['activity']['key']['id']);
        $this->assertEquals('Test Activity', $array['activity']['name']);
        $this->assertEquals('Test description', $array['description']);
        $this->assertEquals('Client description', $array['clientDescription']);
        $this->assertEquals(2.5, $array['time']);
        $this->assertEquals('2024-01-15', $array['date']);
        $this->assertIsArray($array['role']);
        $this->assertEquals(5, $array['role']['id']);
        $this->assertEquals('Manager', $array['role']['name']);
        $this->assertFalse($array['individualAction']);
        $this->assertEquals($frameUuids, $array['frameUuids']);
        $this->assertEquals(42, $array['zebraId']);
        $this->assertEquals($updatedAt->timestamp, $array['updatedAt']);
    }

    public function testToString(): void
    {
        $uuid = Uuid::random();
        $role = new Role(5, null, 'Manager', 'Manager', 'employee', 'active');
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            2.5,
            '2024-01-15',
            $role,
            false,
            [],
            42
        );

        $string = (string) $timesheet;

        $this->assertStringContainsString($uuid->getHex(), $string);
        $this->assertStringContainsString('100', $string);
        $this->assertStringContainsString('Test Activity', $string);
        $this->assertStringContainsString('2024-01-15', $string);
        $this->assertStringContainsString('2.50', $string);
        $this->assertStringContainsString('42', $string);
    }

    public function testToStringWithNullZebraId(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15);
        $role = new Role(5, null, 'Manager', 'Manager', 'employee', 'active');
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $role,
            false,
            [],
            null
        );

        $string = (string) $timesheet;

        $this->assertStringContainsString('null', $string);
    }

    public function testDateWithStringInput(): void
    {
        $uuid = Uuid::random();
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            '2024-01-15',
            $this->role,
            false,
            []
        );

        $this->assertEquals('2024-01-15', $timesheet->date->format('Y-m-d'));
    }

    public function testDateWithTimestampInput(): void
    {
        $uuid = Uuid::random();
        // Create timestamp for 2024-01-15 at midnight UTC
        $timestamp = Carbon::createFromDate(2024, 1, 15, 'UTC')->startOfDay()->timestamp;
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $timestamp,
            $this->role,
            false,
            []
        );

        // The date should be normalized to start of day in UTC
        $this->assertEquals('2024-01-15', $timesheet->date->format('Y-m-d'));
    }

    public function testConstructorWithDoNotSyncDefaultFalse(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->assertFalse($timesheet->doNotSync);
    }

    public function testConstructorWithDoNotSyncTrue(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [],
            null,
            null,
            true
        );

        $this->assertTrue($timesheet->doNotSync);
    }

    public function testToArrayIncludesDoNotSync(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [],
            null,
            null,
            true
        );

        $array = $timesheet->toArray();
        $this->assertArrayHasKey('doNotSync', $array);
        $this->assertTrue($array['doNotSync']);
    }

    public function testToArrayIncludesDoNotSyncFalse(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = new Timesheet(
            $uuid,
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [],
            null,
            null,
            false
        );

        $array = $timesheet->toArray();
        $this->assertArrayHasKey('doNotSync', $array);
        $this->assertFalse($array['doNotSync']);
    }
}
