<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Activity;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\LocalActivityRepository;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\LocalProjectRepositoryInterface;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectStatus;
use Tcrawf\Zebra\Tests\Helper\TestEntityFactory;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

class LocalActivityRepositoryTest extends TestCase
{
    private LocalProjectRepositoryInterface&MockObject $projectRepository;
    private LocalActivityRepository $repository;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(LocalProjectRepositoryInterface::class);
        $this->repository = new LocalActivityRepository($this->projectRepository);
    }

    public function testAllReturnsOnlyLocalActivitiesFromActiveProjectsByDefault(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $localActivityUuid = Uuid::random();
        $localActivity = TestEntityFactory::createLocalActivity(
            $localActivityUuid,
            'Local Activity',
            'Description',
            $projectEntityKey
        );
        $zebraActivity = TestEntityFactory::createActivity(
            EntityKey::zebra(1),
            'Zebra Activity',
            'Description',
            EntityKey::zebra(100)
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$localActivity, $zebraActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->all();

        $this->assertCount(1, $result);
        $this->assertSame(EntitySource::Local, $result[0]->entityKey->source);
        $this->assertEquals('Local Activity', $result[0]->name);
    }

    public function testAllReturnsOnlyLocalActivitiesWhenActiveOnlyIsTrue(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $localActivityUuid = Uuid::random();
        $localActivity = TestEntityFactory::createLocalActivity(
            $localActivityUuid,
            'Local Activity',
            'Description',
            $projectEntityKey
        );
        $zebraActivity = TestEntityFactory::createActivity(
            EntityKey::zebra(1),
            'Zebra Activity',
            'Description',
            EntityKey::zebra(100)
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$localActivity, $zebraActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->all(true);

        $this->assertCount(1, $result);
        $this->assertSame(EntitySource::Local, $result[0]->entityKey->source);
    }

    public function testAllReturnsLocalActivitiesFromAllProjectsWhenActiveOnlyIsFalse(): void
    {
        $activeProjectUuid = Uuid::random();
        $activeProjectEntityKey = EntityKey::local($activeProjectUuid);
        $inactiveProjectUuid = Uuid::random();
        $inactiveProjectEntityKey = EntityKey::local($inactiveProjectUuid);

        $activeLocalActivityUuid = Uuid::random();
        $activeLocalActivity = TestEntityFactory::createLocalActivity(
            $activeLocalActivityUuid,
            'Active Local Activity',
            'Description',
            $activeProjectEntityKey
        );

        $inactiveLocalActivityUuid = Uuid::random();
        $inactiveLocalActivity = TestEntityFactory::createLocalActivity(
            $inactiveLocalActivityUuid,
            'Inactive Local Activity',
            'Description',
            $inactiveProjectEntityKey
        );

        $activeProject = new Project(
            $activeProjectEntityKey,
            'Active Project',
            'Description',
            1,
            [$activeLocalActivity]
        );
        $inactiveProject = new Project(
            $inactiveProjectEntityKey,
            'Inactive Project',
            'Description',
            0,
            [$inactiveLocalActivity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([$activeProject, $inactiveProject]);

        $result = $this->repository->all(false);

        $this->assertCount(2, $result);
        $this->assertSame(EntitySource::Local, $result[0]->entityKey->source);
        $this->assertSame(EntitySource::Local, $result[1]->entityKey->source);
    }

    public function testAllReturnsEmptyArrayWhenNoProjects(): void
    {
        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([]);

        $result = $this->repository->all();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testAllReturnsEmptyArrayWhenNoLocalActivities(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $zebraActivity = TestEntityFactory::createActivity(
            EntityKey::zebra(1),
            'Zebra Activity',
            'Description',
            EntityKey::zebra(100)
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$zebraActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->all();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetReturnsActivityForValidLocalEntityKey(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = TestEntityFactory::createLocalActivity(
            null,
            'Test Activity',
            'Description',
            $projectEntityKey,
            null,
            $activityEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $result = $this->repository->get($activityEntityKey);

        $this->assertNotNull($result);
        $this->assertEquals('Test Activity', $result->name);
        $this->assertSame(EntitySource::Local, $result->entityKey->source);
    }

    public function testGetReturnsNullForNonLocalEntityKey(): void
    {
        $zebraEntityKey = EntityKey::zebra(1);

        $result = $this->repository->get($zebraEntityKey);

        $this->assertNull($result);
        $this->projectRepository
            ->expects($this->never())
            ->method('getByActivityId');
    }

    public function testGetReturnsNullForNonUuidId(): void
    {
        // Create an entity key with a non-UUID id (this shouldn't happen in practice)
        // Since EntityKey::local() requires UuidInterface, we'll test with a Zebra entity key
        // which has an integer ID, not a UUID
        $zebraEntityKey = EntityKey::zebra(1);

        $result = $this->repository->get($zebraEntityKey);

        $this->assertNull($result);
        $this->projectRepository
            ->expects($this->never())
            ->method('getByActivityId');
    }

    public function testGetReturnsNullWhenProjectNotFound(): void
    {
        $activityUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn(null);

        $result = $this->repository->get($activityEntityKey);

        $this->assertNull($result);
    }

    public function testGetReturnsNullWhenActivityNotFoundInProject(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $otherActivityUuid = Uuid::random();
        $otherActivity = new Activity(
            EntityKey::local($otherActivityUuid),
            'Other Activity',
            'Description',
            $projectEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$otherActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $result = $this->repository->get($activityEntityKey);

        $this->assertNull($result);
    }

    public function testGetByAliasReturnsActivityForValidAlias(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = TestEntityFactory::createLocalActivity(
            $activityUuid,
            'Test Activity',
            'Description',
            $projectEntityKey,
            'test-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->repository->getByAlias('test-alias');

        $this->assertNotNull($result);
        $this->assertEquals('test-alias', $result->alias);
        $this->assertEquals('Test Activity', $result->name);
    }

    public function testGetByAliasReturnsNullWhenProjectNotFound(): void
    {
        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('non-existent')
            ->willReturn(null);

        $result = $this->repository->getByAlias('non-existent');

        $this->assertNull($result);
    }

    public function testGetByAliasReturnsNullForInactiveProjectWhenActiveOnlyIsTrue(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = TestEntityFactory::createLocalActivity(
            $activityUuid,
            'Test Activity',
            'Description',
            $projectEntityKey,
            'test-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 0, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->repository->getByAlias('test-alias', true);

        $this->assertNull($result);
    }

    public function testGetByAliasReturnsActivityFromInactiveProjectWhenActiveOnlyIsFalse(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = TestEntityFactory::createLocalActivity(
            $activityUuid,
            'Test Activity',
            'Description',
            $projectEntityKey,
            'test-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 0, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->repository->getByAlias('test-alias', false);

        $this->assertNotNull($result);
        $this->assertEquals('test-alias', $result->alias);
    }

    public function testGetByAliasDefaultsToActiveOnly(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = TestEntityFactory::createLocalActivity(
            $activityUuid,
            'Test Activity',
            'Description',
            $projectEntityKey,
            'test-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 0, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->repository->getByAlias('test-alias');

        $this->assertNull($result);
    }

    public function testGetByAliasReturnsNullWhenActivityNotFoundInProject(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $otherActivityUuid = Uuid::random();
        $otherActivity = new Activity(
            EntityKey::local($otherActivityUuid),
            'Other Activity',
            'Description',
            $projectEntityKey,
            'other-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$otherActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->repository->getByAlias('test-alias');

        $this->assertNull($result);
    }

    public function testGetByAliasReturnsNullWhenActivityIsNotLocal(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $zebraActivity = new Activity(
            EntityKey::zebra(1),
            'Zebra Activity',
            'Description',
            EntityKey::zebra(100),
            'test-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$zebraActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($project);

        $result = $this->repository->getByAlias('test-alias');

        $this->assertNull($result);
    }

    public function testSearchByNameOrAliasMatchesByName(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local($activityUuid),
            'Test Activity Name',
            'Description',
            $projectEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByNameOrAlias('Activity');

        $this->assertCount(1, $result);
        $this->assertEquals('Test Activity Name', $result[0]->name);
    }

    public function testSearchByNameOrAliasMatchesByAlias(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = TestEntityFactory::createLocalActivity(
            $activityUuid,
            'Test Activity',
            'Description',
            $projectEntityKey,
            'test-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByNameOrAlias('alias');

        $this->assertCount(1, $result);
        $this->assertEquals('test-alias', $result[0]->alias);
    }

    public function testSearchByNameOrAliasIsCaseInsensitive(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local($activityUuid),
            'Test Activity',
            'Description',
            $projectEntityKey,
            'TEST-ALIAS'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByNameOrAlias('test');

        $this->assertCount(1, $result);
    }

    public function testSearchByNameOrAliasReturnsEmptyArrayWhenNoMatches(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local($activityUuid),
            'Test Activity',
            'Description',
            $projectEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByNameOrAlias('NonExistent');

        $this->assertCount(0, $result);
    }

    public function testSearchByNameOrAliasRespectsActiveOnlyFlag(): void
    {
        $activeProjectUuid = Uuid::random();
        $activeProjectEntityKey = EntityKey::local($activeProjectUuid);
        $inactiveProjectUuid = Uuid::random();
        $inactiveProjectEntityKey = EntityKey::local($inactiveProjectUuid);

        $activeActivityUuid = Uuid::random();
        $activeActivity = new Activity(
            EntityKey::local($activeActivityUuid),
            'Active Activity',
            'Description',
            $activeProjectEntityKey
        );

        $inactiveActivityUuid = Uuid::random();
        $inactiveActivity = new Activity(
            EntityKey::local($inactiveActivityUuid),
            'Inactive Activity',
            'Description',
            $inactiveProjectEntityKey
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
            ->expects($this->exactly(2))
            ->method('all')
            ->willReturnCallback(function ($statuses) use ($activeProject, $inactiveProject) {
                if ($statuses === [ProjectStatus::Active]) {
                    return [$activeProject];
                }
                return [$activeProject, $inactiveProject];
            });

        $resultActiveOnly = $this->repository->searchByNameOrAlias('Activity', true);
        $this->assertCount(1, $resultActiveOnly);

        $resultAll = $this->repository->searchByNameOrAlias('Activity', false);
        $this->assertCount(2, $resultAll);
    }

    public function testSearchByNameOrAliasDefaultsToActiveOnly(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local($activityUuid),
            'Test Activity',
            'Description',
            $projectEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByNameOrAlias('Activity');

        $this->assertCount(1, $result);
    }

    public function testSearchByNameOrAliasHandlesNullAlias(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local($activityUuid),
            'Test Activity',
            'Description',
            $projectEntityKey,
            null
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByNameOrAlias('Activity');

        $this->assertCount(1, $result);
    }

    public function testSearchByAliasMatchesByAlias(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = TestEntityFactory::createLocalActivity(
            $activityUuid,
            'Test Activity',
            'Description',
            $projectEntityKey,
            'test-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByAlias('alias');

        $this->assertCount(1, $result);
        $this->assertEquals('test-alias', $result[0]->alias);
    }

    public function testSearchByAliasIsCaseInsensitive(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local($activityUuid),
            'Test Activity',
            'Description',
            $projectEntityKey,
            'TEST-ALIAS'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByAlias('test');

        $this->assertCount(1, $result);
    }

    public function testSearchByAliasReturnsEmptyArrayWhenNoMatches(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityUuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local($activityUuid),
            'Test Activity',
            'Description',
            $projectEntityKey,
            'other-alias'
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByAlias('non-existent');

        $this->assertCount(0, $result);
    }

    public function testSearchByAliasIgnoresActivitiesWithoutAlias(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $activityWithAliasUuid = Uuid::random();
        $activityWithAlias = new Activity(
            EntityKey::local($activityWithAliasUuid),
            'Activity With Alias',
            'Description',
            $projectEntityKey,
            'test-alias'
        );
        $activityWithoutAliasUuid = Uuid::random();
        $activityWithoutAlias = new Activity(
            EntityKey::local($activityWithoutAliasUuid),
            'Activity Without Alias',
            'Description',
            $projectEntityKey,
            null
        );
        $project = new Project(
            $projectEntityKey,
            'Test Project',
            'Description',
            1,
            [$activityWithAlias, $activityWithoutAlias]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$project]);

        $result = $this->repository->searchByAlias('test');

        $this->assertCount(1, $result);
        $this->assertEquals('test-alias', $result[0]->alias);
    }

    public function testSearchByAliasOnlySearchesActiveProjects(): void
    {
        $activeProjectUuid = Uuid::random();
        $activeProjectEntityKey = EntityKey::local($activeProjectUuid);
        $inactiveProjectUuid = Uuid::random();
        $inactiveProjectEntityKey = EntityKey::local($inactiveProjectUuid);

        $activeActivityUuid = Uuid::random();
        $activeActivity = new Activity(
            EntityKey::local($activeActivityUuid),
            'Active Activity',
            'Description',
            $activeProjectEntityKey,
            'active-alias'
        );

        $inactiveActivityUuid = Uuid::random();
        $inactiveActivity = new Activity(
            EntityKey::local($inactiveActivityUuid),
            'Inactive Activity',
            'Description',
            $inactiveProjectEntityKey,
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

        $result = $this->repository->searchByAlias('alias');

        $this->assertCount(1, $result);
        $this->assertEquals('active-alias', $result[0]->alias);
    }

    public function testCreateSuccessfullyCreatesActivity(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, []);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) {
                return count($activities) === 1
                    && $activities[0]->name === 'New Activity'
                    && $activities[0]->description === 'New Description'
                    && $activities[0]->entityKey->source === EntitySource::Local;
            }))
            ->willReturnCallback(function ($key, $activities) use ($project) {
                return new Project(
                    $project->entityKey,
                    $project->name,
                    $project->description,
                    $project->status,
                    $activities
                );
            });

        $result = $this->repository->create('New Activity', 'New Description', $projectEntityKey);

        $this->assertNotNull($result);
        $this->assertEquals('New Activity', $result->name);
        $this->assertEquals('New Description', $result->description);
        $this->assertSame(EntitySource::Local, $result->entityKey->source);
        $this->assertInstanceOf(UuidInterface::class, $result->entityKey->id);
    }

    public function testCreateWithAliasSuccessfullyCreatesActivity(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, []);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) {
                return count($activities) === 1
                    && $activities[0]->alias === 'new-alias';
            }))
            ->willReturnCallback(function ($key, $activities) use ($project) {
                return new Project(
                    $project->entityKey,
                    $project->name,
                    $project->description,
                    $project->status,
                    $activities
                );
            });

        $result = $this->repository->create('New Activity', 'New Description', $projectEntityKey, 'new-alias');

        $this->assertNotNull($result);
        $this->assertEquals('new-alias', $result->alias);
    }

    public function testCreateThrowsExceptionForNonLocalProjectEntityKey(): void
    {
        $zebraProjectEntityKey = EntityKey::zebra(100);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Project entity key must be from Local source');

        $this->repository->create('New Activity', 'New Description', $zebraProjectEntityKey);
    }

    public function testCreateThrowsExceptionWhenProjectNotFound(): void
    {
        $projectUuid = Uuid::random();
        $projectEntityKey = EntityKey::local($projectUuid);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Project not found');

        $this->repository->create('New Activity', 'New Description', $projectEntityKey);
    }

    public function testUpdateSuccessfullyUpdatesActivity(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = new Activity(
            $activityEntityKey,
            'Original Name',
            'Original Description',
            $projectEntityKey,
            'original-alias'
        );
        $project = new Project(
            $projectEntityKey,
            'Test Project',
            'Description',
            1,
            [$activity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) use ($activityUuid) {
                return count($activities) === 1
                    && $activities[0]->name === 'Updated Name'
                    && $activities[0]->description === 'Updated Description'
                    && $activities[0]->alias === 'updated-alias'
                    && $activities[0]->entityKey->id instanceof UuidInterface
                    && $activities[0]->entityKey->id->getHex() === $activityUuid->getHex();
            }))
            ->willReturnCallback(function ($key, $activities) use ($project) {
                return new Project(
                    $project->entityKey,
                    $project->name,
                    $project->description,
                    $project->status,
                    $activities
                );
            });

        $result = $this->repository->update($activityUuid, 'Updated Name', 'Updated Description', 'updated-alias');

        $this->assertNotNull($result);
        $this->assertEquals('Updated Name', $result->name);
        $this->assertEquals('Updated Description', $result->description);
        $this->assertEquals('updated-alias', $result->alias);
    }

    public function testUpdateWithUuidStringSuccessfullyUpdatesActivity(): void
    {
        $activityUuid = Uuid::random();
        $activityUuidString = $activityUuid->getHex();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = new Activity($activityEntityKey, 'Original Name', 'Original Description', $projectEntityKey);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) use ($activityUuidString) {
                return count($activities) === 1
                    && $activities[0]->name === 'Updated Name'
                    && $activities[0]->entityKey->id instanceof UuidInterface
                    && $activities[0]->entityKey->id->getHex() === $activityUuidString;
            }))
            ->willReturnCallback(function ($key, $activities) use ($project) {
                return new Project(
                    $project->entityKey,
                    $project->name,
                    $project->description,
                    $project->status,
                    $activities
                );
            });

        $result = $this->repository->update($activityUuidString, 'Updated Name');

        $this->assertNotNull($result);
        $this->assertEquals('Updated Name', $result->name);
    }

    public function testUpdateWithPartialDataPreservesExistingValues(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = new Activity(
            $activityEntityKey,
            'Original Name',
            'Original Description',
            $projectEntityKey,
            'original-alias'
        );
        $project = new Project(
            $projectEntityKey,
            'Test Project',
            'Description',
            1,
            [$activity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) {
                return count($activities) === 1
                    && $activities[0]->name === 'Updated Name'
                    && $activities[0]->description === 'Original Description'
                    && $activities[0]->alias === 'original-alias';
            }))
            ->willReturnCallback(function ($key, $activities) use ($project) {
                return new Project(
                    $project->entityKey,
                    $project->name,
                    $project->description,
                    $project->status,
                    $activities
                );
            });

        $result = $this->repository->update($activityUuid, 'Updated Name');

        $this->assertNotNull($result);
        $this->assertEquals('Updated Name', $result->name);
        $this->assertEquals('Original Description', $result->description);
        $this->assertEquals('original-alias', $result->alias);
    }

    public function testUpdateWithNullAliasPreservesExistingAlias(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = new Activity(
            $activityEntityKey,
            'Original Name',
            'Original Description',
            $projectEntityKey,
            'original-alias'
        );
        $project = new Project(
            $projectEntityKey,
            'Test Project',
            'Description',
            1,
            [$activity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) {
                return count($activities) === 1
                    && $activities[0]->alias === 'original-alias';
            }))
            ->willReturnCallback(function ($key, $activities) use ($project) {
                return new Project(
                    $project->entityKey,
                    $project->name,
                    $project->description,
                    $project->status,
                    $activities
                );
            });

        $result = $this->repository->update($activityUuid, null, null, null);

        $this->assertNotNull($result);
        $this->assertEquals('original-alias', $result->alias);
    }

    public function testUpdateWithEmptyStringAliasSetsAliasToNull(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = new Activity(
            $activityEntityKey,
            'Original Name',
            'Original Description',
            $projectEntityKey,
            'original-alias'
        );
        $project = new Project(
            $projectEntityKey,
            'Test Project',
            'Description',
            1,
            [$activity]
        );

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) {
                return count($activities) === 1
                    && $activities[0]->alias === '';
            }))
            ->willReturnCallback(function ($key, $activities) use ($project) {
                return new Project(
                    $project->entityKey,
                    $project->name,
                    $project->description,
                    $project->status,
                    $activities
                );
            });

        $result = $this->repository->update($activityUuid, null, null, '');

        $this->assertNotNull($result);
        $this->assertEquals('', $result->alias);
    }

    public function testUpdateThrowsExceptionWhenActivityNotFound(): void
    {
        $activityUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Activity with UUID {$activityUuid->getHex()} not found");

        $this->repository->update($activityUuid, 'Updated Name');
    }

    public function testUpdateThrowsExceptionWhenParentProjectNotFound(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = new Activity($activityEntityKey, 'Original Name', 'Original Description', $projectEntityKey);
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parent project not found');

        $this->repository->update($activityUuid, 'Updated Name');
    }

    public function testDeleteSuccessfullyDeletesActivity(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = TestEntityFactory::createLocalActivity(
            null,
            'Test Activity',
            'Description',
            $projectEntityKey,
            null,
            $activityEntityKey
        );
        $otherActivityUuid = Uuid::random();
        $otherActivity = new Activity(
            EntityKey::local($otherActivityUuid),
            'Other Activity',
            'Description',
            $projectEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity, $otherActivity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) use ($otherActivityUuid) {
                return count($activities) === 1
                    && $activities[0]->entityKey->id instanceof UuidInterface
                    && $activities[0]->entityKey->id->getHex() === $otherActivityUuid->getHex();
            }));

        $this->repository->delete($activityUuid);
    }

    public function testDeleteWithUuidStringSuccessfullyDeletesActivity(): void
    {
        $activityUuid = Uuid::random();
        $activityUuidString = $activityUuid->getHex();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = TestEntityFactory::createLocalActivity(
            null,
            'Test Activity',
            'Description',
            $projectEntityKey,
            null,
            $activityEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($projectEntityKey, $this->callback(function ($activities) {
                return count($activities) === 0;
            }));

        $this->repository->delete($activityUuidString);
    }

    public function testDeleteThrowsExceptionWhenActivityNotFound(): void
    {
        $activityUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Activity with UUID {$activityUuid->getHex()} not found");

        $this->repository->delete($activityUuid);
    }

    public function testDeleteThrowsExceptionWhenParentProjectNotFound(): void
    {
        $activityUuid = Uuid::random();
        $projectUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);
        $projectEntityKey = EntityKey::local($projectUuid);
        $activity = TestEntityFactory::createLocalActivity(
            null,
            'Test Activity',
            'Description',
            $projectEntityKey,
            null,
            $activityEntityKey
        );
        $project = new Project($projectEntityKey, 'Test Project', 'Description', 1, [$activity]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->projectRepository
            ->expects($this->once())
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parent project not found');

        $this->repository->delete($activityUuid);
    }
}
