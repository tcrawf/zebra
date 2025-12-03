<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Project;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Project\Project;

class ProjectTest extends TestCase
{
    public function testConstructorAndProperties(): void
    {
        $projectEntityKey = EntityKey::zebra(100);
        $activityEntityKey = EntityKey::zebra(1);
        $activity = new Activity($activityEntityKey, 'Activity 1', 'Description', $projectEntityKey);
        $project = new Project($projectEntityKey, 'Test Project', 'Project Description', 1, [$activity]);

        $this->assertEquals($projectEntityKey, $project->entityKey);
        $this->assertEquals('Test Project', $project->name);
        $this->assertEquals('Project Description', $project->description);
        $this->assertEquals(1, $project->status);
        $this->assertCount(1, $project->activities);
        $this->assertEquals($activity, $project->activities[0]);
    }

    public function testConstructorWithEmptyActivities(): void
    {
        $projectEntityKey = EntityKey::zebra(100);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, []);

        $this->assertEmpty($project->activities);
    }

    public function testToString(): void
    {
        $projectEntityKey = EntityKey::zebra(100);
        $activity1EntityKey = EntityKey::zebra(1);
        $activity2EntityKey = EntityKey::zebra(2);
        $activity1 = new Activity($activity1EntityKey, 'Activity 1', '', $projectEntityKey);
        $activity2 = new Activity($activity2EntityKey, 'Activity 2', '', $projectEntityKey);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity1, $activity2]);

        $string = (string) $project;

        $this->assertStringContainsString('Project(entityKey=', $string);
        $this->assertStringContainsString('name=Test Project', $string);
        $this->assertStringContainsString('activities=2', $string);
    }
}
