<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Activity;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;

class ActivityTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity(
            $activityEntityKey,
            'Test Activity',
            'Test Description',
            $projectEntityKey,
            'test-alias'
        );

        $this->assertEquals($activityEntityKey, $activity->entityKey);
        $this->assertEquals('Test Activity', $activity->name);
        $this->assertEquals('Test Description', $activity->description);
        $this->assertEquals($projectEntityKey, $activity->projectEntityKey);
        $this->assertEquals('test-alias', $activity->alias);
    }

    public function testConstructorWithoutAlias(): void
    {
        $activityEntityKey = EntityKey::zebra(2);
        $projectEntityKey = EntityKey::zebra(200);
        $activity = new Activity($activityEntityKey, 'Activity 2', 'Description 2', $projectEntityKey);

        $this->assertEquals($activityEntityKey, $activity->entityKey);
        $this->assertNull($activity->alias);
    }

    public function testToString(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity(
            $activityEntityKey,
            'Test Activity',
            'Test Description',
            $projectEntityKey,
            'test-alias'
        );
        $string = (string) $activity;

        $this->assertStringContainsString('Activity(entityKey=', $string);
        $this->assertStringContainsString('name=Test Activity', $string);
        $this->assertStringContainsString('projectEntityKey=', $string);
        $this->assertStringContainsString('alias=test-alias', $string);
    }

    public function testToStringWithoutAlias(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity($activityEntityKey, 'Test Activity', 'Test Description', $projectEntityKey);
        $string = (string) $activity;

        $this->assertStringContainsString('alias=null', $string);
    }
}
