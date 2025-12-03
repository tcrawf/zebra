<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameFormatter;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Uuid\Uuid;

class FrameFormatterTest extends TestCase
{
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->activity = new Activity(EntityKey::zebra(1), 'Test Activity', 'Description', EntityKey::zebra(100));
        $this->role = new Role(1, null, 'Developer');
    }

    public function testFormatStart(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now('UTC')->subHour();
        $frame = new Frame($uuid, $startTime, null, $this->activity, false, $this->role);

        $formatted = FrameFormatter::formatStart($frame);
        $this->assertEquals($startTime->timestamp, $formatted->timestamp);
    }

    public function testFormatStartWithTimezone(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now('UTC')->subHour();
        $frame = new Frame($uuid, $startTime, null, $this->activity, false, $this->role);

        $formatted = FrameFormatter::formatStart($frame, 'America/New_York');
        $this->assertEquals('America/New_York', $formatted->timezone->getName());
    }

    public function testFormatStop(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now('UTC')->subHour();
        $stopTime = Carbon::now('UTC');
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role);

        $formatted = FrameFormatter::formatStop($frame);
        $this->assertNotNull($formatted);
        $this->assertEquals($stopTime->timestamp, $formatted->timestamp);
    }

    public function testFormatStopWithActiveFrame(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame($uuid, Carbon::now(), null, $this->activity, false, $this->role);

        $formatted = FrameFormatter::formatStop($frame);
        $this->assertNull($formatted);
    }

    public function testFormatStopWithTimezone(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now('UTC')->subHour();
        $stopTime = Carbon::now('UTC');
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role);

        $formatted = FrameFormatter::formatStop($frame, 'America/New_York');
        $this->assertNotNull($formatted);
        $this->assertEquals('America/New_York', $formatted->timezone->getName());
    }

    public function testFormatUpdatedAt(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now('UTC');
        $frame = new Frame($uuid, $startTime, null, $this->activity, false, $this->role);

        $formatted = FrameFormatter::formatUpdatedAt($frame);
        $this->assertInstanceOf(Carbon::class, $formatted);
    }

    public function testFormatUpdatedAtWithTimezone(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame($uuid, Carbon::now(), null, $this->activity, false, $this->role);

        $formatted = FrameFormatter::formatUpdatedAt($frame, 'Europe/London');
        $this->assertEquals('Europe/London', $formatted->timezone->getName());
    }

    public function testGetDay(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::parse('2024-01-15 14:30:00', 'UTC');
        $frame = new Frame($uuid, $startTime, null, $this->activity, false, $this->role);

        $day = FrameFormatter::getDay($frame);
        $this->assertEquals('2024-01-15 00:00:00', $day->format('Y-m-d H:i:s'));
    }

    public function testGetDuration(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHours(2);
        $stopTime = Carbon::now();
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role);

        $duration = FrameFormatter::getDuration($frame);
        $this->assertNotNull($duration);
        $this->assertGreaterThanOrEqual(7200, $duration);
    }

    public function testGetDurationFormatted(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHours(2)->subMinutes(30)->subSeconds(45);
        $stopTime = Carbon::now();
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role);

        $formatted = FrameFormatter::getDurationFormatted($frame);
        $this->assertNotNull($formatted);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $formatted);
    }

    public function testGetDurationFormattedWithActiveFrame(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame($uuid, Carbon::now(), null, $this->activity, false, $this->role);

        $formatted = FrameFormatter::getDurationFormatted($frame);
        $this->assertNull($formatted);
    }

    public function testFormatForDisplay(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame($uuid, $startTime, $stopTime, $this->activity, false, $this->role, 'ABC-123 Description');

        $display = FrameFormatter::formatForDisplay($frame);

        $this->assertEquals($uuid->getHex(), $display['uuid']);
        $this->assertEquals('Test Activity', $display['activity']);
        $this->assertIsArray($display['project_entityKey']);
        $this->assertEquals('100', $display['project_entityKey']['id']);
        $this->assertNotNull($display['start']);
        $this->assertNotNull($display['stop']);
        $this->assertContains('ABC-123', $display['issue_keys']);
        $this->assertNotNull($display['duration']);
        $this->assertNotNull($display['updated_at']);
    }

    public function testFormatForDisplayWithActiveFrame(): void
    {
        $uuid = Uuid::random();
        $frame = new Frame($uuid, Carbon::now(), null, $this->activity, false, $this->role);

        $display = FrameFormatter::formatForDisplay($frame);

        $this->assertNull($display['stop']);
        $this->assertNull($display['duration']);
    }

    public function testFormatForDisplayWithTimezone(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::parse('2024-01-15 14:30:00', 'UTC');
        $frame = new Frame($uuid, $startTime, null, $this->activity, false, $this->role);

        $display = FrameFormatter::formatForDisplay($frame, 'America/New_York');

        $this->assertStringContainsString('2024-01-15', $display['start']);
    }
}
