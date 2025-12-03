<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Activity;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ZebraActivityRepository;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ZebraProjectRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectStatus;

class ActivityRepositoryTest extends TestCase
{
    private ZebraProjectRepositoryInterface&MockObject $projectRepository;
    private ZebraActivityRepository $activityRepository;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(ZebraProjectRepositoryInterface::class);
        $this->activityRepository = new ZebraActivityRepository($this->projectRepository);
    }

    public function testGetById(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity($activityEntityKey, 'Test Activity', 'Description', $projectEntityKey);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $result = $this->activityRepository->get(EntityKey::zebra(1));

        $this->assertNotNull($result);
        $this->assertSame(EntitySource::Zebra, $result->entityKey->source);
        $this->assertSame(1, $result->entityKey->id);
        $this->assertEquals('Test Activity', $result->name);
    }

    public function testGetByIdNotFound(): void
    {
        $activityEntityKey = EntityKey::zebra(999);
        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn(null);

        $result = $this->activityRepository->get(EntityKey::zebra(999));

        $this->assertNull($result);
    }

    public function testGetByAlias(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity($activityEntityKey, 'Test Activity', 'Description', $projectEntityKey, 'test-alias');
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->activityRepository->getByAlias('test-alias');

        $this->assertNotNull($result);
        $this->assertEquals('test-alias', $result->alias);
        $this->assertSame(EntitySource::Zebra, $result->entityKey->source);
        $this->assertSame(1, $result->entityKey->id);
    }

    public function testGetByAliasNotFound(): void
    {
        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('non-existent')
            ->willReturn(null);

        $result = $this->activityRepository->getByAlias('non-existent');

        $this->assertNull($result);
    }

    public function testGetByIdReturnsActivityFromInactiveProject(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity($activityEntityKey, 'Test Activity', 'Description', $projectEntityKey);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 0, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $result = $this->activityRepository->get(EntityKey::zebra(1));

        $this->assertNotNull($result);
        $this->assertSame(EntitySource::Zebra, $result->entityKey->source);
        $this->assertSame(1, $result->entityKey->id);
        $this->assertEquals('Test Activity', $result->name);
    }

    public function testGetByAliasReturnsNullForInactiveProjectWhenActiveOnlyIsTrue(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity($activityEntityKey, 'Test Activity', 'Description', $projectEntityKey, 'test-alias');
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 0, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->activityRepository->getByAlias('test-alias', true);

        $this->assertNull($result);
    }

    public function testGetByAliasReturnsActivityFromInactiveProjectWhenActiveOnlyIsFalse(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity($activityEntityKey, 'Test Activity', 'Description', $projectEntityKey, 'test-alias');
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 0, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->activityRepository->getByAlias('test-alias', false);

        $this->assertNotNull($result);
        $this->assertEquals('test-alias', $result->alias);
        $this->assertSame(EntitySource::Zebra, $result->entityKey->source);
        $this->assertSame(1, $result->entityKey->id);
    }

    public function testGetByAliasDefaultsToActiveOnly(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $projectEntityKey = EntityKey::zebra(100);
        $activity = new Activity($activityEntityKey, 'Test Activity', 'Description', $projectEntityKey, 'test-alias');
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 0, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->activityRepository->getByAlias('test-alias');

        $this->assertNull($result);
    }

    public function testAllOnlyReturnsActivitiesFromActiveProjects(): void
    {
        $activity1EntityKey = EntityKey::zebra(1);
        $activity2EntityKey = EntityKey::zebra(2);
        $activity3EntityKey = EntityKey::zebra(3);
        $project100EntityKey = EntityKey::zebra(100);
        $project200EntityKey = EntityKey::zebra(200);
        $activeActivity1 = new Activity($activity1EntityKey, 'Active Activity 1', 'Description', $project100EntityKey);
        $activeActivity2 = new Activity($activity2EntityKey, 'Active Activity 2', 'Description', $project100EntityKey);
        $inactiveActivity = new Activity($activity3EntityKey, 'Inactive Activity', 'Description', $project200EntityKey);

        $activeProject = new Project(
            $project100EntityKey,
            'Active Project',
            'Description',
            1,
            [$activeActivity1, $activeActivity2]
        );
        $inactiveProject = new Project($project200EntityKey, 'Inactive Project', 'Description', 0, [$inactiveActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$activeProject]);

        $result = $this->activityRepository->all(true);

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->entityKey->id);
        $this->assertEquals(2, $result[1]->entityKey->id);
    }

    public function testAllReturnsAllActivitiesWhenActiveOnlyIsFalse(): void
    {
        $activity1EntityKey = EntityKey::zebra(1);
        $activity2EntityKey = EntityKey::zebra(2);
        $activity3EntityKey = EntityKey::zebra(3);
        $project100EntityKey = EntityKey::zebra(100);
        $project200EntityKey = EntityKey::zebra(200);
        $activeActivity1 = new Activity($activity1EntityKey, 'Active Activity 1', 'Description', $project100EntityKey);
        $activeActivity2 = new Activity($activity2EntityKey, 'Active Activity 2', 'Description', $project100EntityKey);
        $inactiveActivity = new Activity($activity3EntityKey, 'Inactive Activity', 'Description', $project200EntityKey);

        $activeProject = new Project(
            $project100EntityKey,
            'Active Project',
            'Description',
            1,
            [$activeActivity1, $activeActivity2]
        );
        $inactiveProject = new Project($project200EntityKey, 'Inactive Project', 'Description', 0, [$inactiveActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([$activeProject, $inactiveProject]);

        $result = $this->activityRepository->all(false);

        $this->assertCount(3, $result);
    }

    public function testAllDefaultsToActiveOnly(): void
    {
        $activity1EntityKey = EntityKey::zebra(1);
        $activity2EntityKey = EntityKey::zebra(2);
        $activity3EntityKey = EntityKey::zebra(3);
        $project100EntityKey = EntityKey::zebra(100);
        $project200EntityKey = EntityKey::zebra(200);
        $activeActivity1 = new Activity($activity1EntityKey, 'Active Activity 1', 'Description', $project100EntityKey);
        $activeActivity2 = new Activity($activity2EntityKey, 'Active Activity 2', 'Description', $project100EntityKey);
        $inactiveActivity = new Activity($activity3EntityKey, 'Inactive Activity', 'Description', $project200EntityKey);

        $activeProject = new Project(
            $project100EntityKey,
            'Active Project',
            'Description',
            1,
            [$activeActivity1, $activeActivity2]
        );
        $inactiveProject = new Project($project200EntityKey, 'Inactive Project', 'Description', 0, [$inactiveActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$activeProject]);

        $result = $this->activityRepository->all();

        $this->assertCount(2, $result);
    }



    public function testSearchByAliasOnlyReturnsActivitiesFromActiveProjects(): void
    {
        $activeActivityEntityKey = EntityKey::zebra(1);
        $inactiveActivityEntityKey = EntityKey::zebra(2);
        $activeProjectEntityKey = EntityKey::zebra(100);
        $inactiveProjectEntityKey = EntityKey::zebra(200);
        $activeActivity = new Activity(
            $activeActivityEntityKey,
            'Active Activity',
            'Description',
            EntityKey::zebra(100),
            'active-alias'
        );
        $inactiveActivity = new Activity(
            $inactiveActivityEntityKey,
            'Inactive Activity',
            'Description',
            EntityKey::zebra(200),
            'inactive-alias'
        );

        $activeProject = new Project($activeProjectEntityKey, 'Active Project', 'Description', 1, [$activeActivity]);
        $inactiveProject = new Project(
            $inactiveProjectEntityKey,
            'Inactive Project',
            'Description',
            0,
            [$inactiveActivity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$activeProject]);

        $result = $this->activityRepository->searchByAlias('alias');

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->entityKey->id);
        $this->assertEquals('active-alias', $result[0]->alias);
    }

    public function testSearchByNameOrAliasOnlyReturnsActivitiesFromActiveProjectsWhenActiveOnlyIsTrue(): void
    {
        $activeActivityEntityKey = EntityKey::zebra(1);
        $inactiveActivityEntityKey = EntityKey::zebra(2);
        $activeProjectEntityKey = EntityKey::zebra(100);
        $inactiveProjectEntityKey = EntityKey::zebra(200);
        $activeActivity = new Activity(
            $activeActivityEntityKey,
            'Active Activity',
            'Description',
            EntityKey::zebra(100),
            'active-alias'
        );
        $inactiveActivity = new Activity(
            $inactiveActivityEntityKey,
            'Inactive Activity',
            'Description',
            EntityKey::zebra(200),
            'inactive-alias'
        );

        $activeProject = new Project($activeProjectEntityKey, 'Active Project', 'Description', 1, [$activeActivity]);
        $inactiveProject = new Project(
            $inactiveProjectEntityKey,
            'Inactive Project',
            'Description',
            0,
            [$inactiveActivity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$activeProject]);

        $result = $this->activityRepository->searchByNameOrAlias('active', true);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->entityKey->id);
    }

    public function testSearchByNameOrAliasReturnsActivitiesFromAllProjectsWhenActiveOnlyIsFalse(): void
    {
        $activeActivityEntityKey = EntityKey::zebra(1);
        $inactiveActivityEntityKey = EntityKey::zebra(2);
        $activeProjectEntityKey = EntityKey::zebra(100);
        $inactiveProjectEntityKey = EntityKey::zebra(200);
        $activeActivity = new Activity(
            $activeActivityEntityKey,
            'Active Activity',
            'Description',
            EntityKey::zebra(100),
            'active-alias'
        );
        $inactiveActivity = new Activity(
            $inactiveActivityEntityKey,
            'Inactive Activity',
            'Description',
            EntityKey::zebra(200),
            'inactive-alias'
        );

        $activeProject = new Project($activeProjectEntityKey, 'Active Project', 'Description', 1, [$activeActivity]);
        $inactiveProject = new Project(
            $inactiveProjectEntityKey,
            'Inactive Project',
            'Description',
            0,
            [$inactiveActivity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([$activeProject, $inactiveProject]);

        $result = $this->activityRepository->searchByNameOrAlias('activity', false);

        $this->assertCount(2, $result);
    }

    public function testSearchByNameOrAliasDefaultsToActiveOnly(): void
    {
        $activeActivityEntityKey = EntityKey::zebra(1);
        $inactiveActivityEntityKey = EntityKey::zebra(2);
        $activeProjectEntityKey = EntityKey::zebra(100);
        $inactiveProjectEntityKey = EntityKey::zebra(200);
        $activeActivity = new Activity(
            $activeActivityEntityKey,
            'Active Activity',
            'Description',
            EntityKey::zebra(100),
            'active-alias'
        );
        $inactiveActivity = new Activity(
            $inactiveActivityEntityKey,
            'Inactive Activity',
            'Description',
            EntityKey::zebra(200),
            'inactive-alias'
        );

        $activeProject = new Project($activeProjectEntityKey, 'Active Project', 'Description', 1, [$activeActivity]);
        $inactiveProject = new Project(
            $inactiveProjectEntityKey,
            'Inactive Project',
            'Description',
            0,
            [$inactiveActivity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$activeProject]);

        $result = $this->activityRepository->searchByNameOrAlias('active');

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->entityKey->id);
    }
}
