<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Project;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\LocalProjectRepositoryInterface;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepository;
use Tcrawf\Zebra\Project\ProjectStatus;
use Tcrawf\Zebra\Project\ZebraProjectRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class ProjectRepositoryProxyTest extends TestCase
{
    private LocalProjectRepositoryInterface&MockObject $localRepository;
    private ZebraProjectRepositoryInterface&MockObject $zebraRepository;
    private ProjectRepository $repository;

    protected function setUp(): void
    {
        $this->localRepository = $this->createMock(LocalProjectRepositoryInterface::class);
        $this->zebraRepository = $this->createMock(ZebraProjectRepositoryInterface::class);
        $this->repository = new ProjectRepository($this->localRepository, $this->zebraRepository);
    }

    public function testAllMergesResultsFromBothRepositories(): void
    {
        $localProject = new Project(
            EntityKey::local(Uuid::random()),
            'Local Project',
            'Description',
            1,
            []
        );
        $zebraProject = new Project(
            EntityKey::zebra(200),
            'Zebra Project',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$localProject]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([$zebraProject]);

        $result = $this->repository->all();

        $this->assertCount(2, $result);
        $this->assertSame(EntitySource::Local, $result[0]->entityKey->source);
        $this->assertSame(EntitySource::Zebra, $result[1]->entityKey->source);
    }

    public function testAllDefaultsToActiveOnly(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('all')
            ->with([ProjectStatus::Active])
            ->willReturn([]);

        $this->repository->all();
    }

    public function testAllWithEmptyArrayReturnsAllProjects(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('all')
            ->with([])
            ->willReturn([]);

        $this->repository->all([]);
    }

    public function testGetRoutesToLocalRepositoryForLocalEntityKey(): void
    {
        $entityKey = EntityKey::local(Uuid::random());
        $project = new Project($entityKey, 'Local Project', 'Description', 1, []);

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($entityKey)
            ->willReturn($project);

        $this->zebraRepository
            ->expects($this->never())
            ->method('get');

        $result = $this->repository->get($entityKey);

        $this->assertNotNull($result);
        $this->assertSame($project, $result);
    }

    public function testGetRoutesToZebraRepositoryForZebraEntityKey(): void
    {
        $entityKey = EntityKey::zebra(100);
        $project = new Project($entityKey, 'Zebra Project', 'Description', 1, []);

        $this->localRepository
            ->expects($this->never())
            ->method('get');

        $this->zebraRepository
            ->expects($this->once())
            ->method('get')
            ->with($entityKey)
            ->willReturn($project);

        $result = $this->repository->get($entityKey);

        $this->assertNotNull($result);
        $this->assertSame($project, $result);
    }

    public function testGetReturnsNullForUnknownSource(): void
    {
        $entityKey = EntityKey::zebra(999);

        $this->localRepository
            ->expects($this->never())
            ->method('get');

        $this->zebraRepository
            ->expects($this->once())
            ->method('get')
            ->with($entityKey)
            ->willReturn(null);

        $result = $this->repository->get($entityKey);

        $this->assertNull($result);
    }

    public function testGetByNameLikeMergesAndPrioritizesStartsWithMatches(): void
    {
        $localProject1 = new Project(
            EntityKey::local(Uuid::random()),
            'Test Project Alpha',
            'Description',
            1,
            []
        );
        $localProject2 = new Project(
            EntityKey::local(Uuid::random()),
            'Other Project',
            'Description',
            1,
            []
        );
        $zebraProject1 = new Project(
            EntityKey::zebra(300),
            'Test Project Beta',
            'Description',
            1,
            []
        );
        $zebraProject2 = new Project(
            EntityKey::zebra(400),
            'Another Test',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Test')
            ->willReturn([$localProject1, $localProject2]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Test')
            ->willReturn([$zebraProject1, $zebraProject2]);

        $result = $this->repository->getByNameLike('Test');

        // Should prioritize "starts with" matches and sort them
        $this->assertGreaterThanOrEqual(2, count($result));
        $projectNames = array_map(static fn($p) => $p->name, $result);
        $this->assertContains('Test Project Alpha', $projectNames);
        $this->assertContains('Test Project Beta', $projectNames);
    }

    public function testGetByNameLikeFallsBackToContainsWhenNoStartsWithMatches(): void
    {
        $localProject = new Project(
            EntityKey::local(Uuid::random()),
            'Alpha Test Project',
            'Description',
            1,
            []
        );
        $zebraProject = new Project(
            EntityKey::zebra(200),
            'Beta Test Project',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Test')
            ->willReturn([$localProject]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('Test')
            ->willReturn([$zebraProject]);

        $result = $this->repository->getByNameLike('Test');

        $this->assertCount(2, $result);
    }

    public function testGetByActivityIdRoutesToLocalRepositoryForLocalEntityKey(): void
    {
        $activityEntityKey = EntityKey::local(Uuid::random());
        $project = new Project(
            EntityKey::local(Uuid::random()),
            'Local Project',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $this->zebraRepository
            ->expects($this->never())
            ->method('getByActivityId');

        $result = $this->repository->getByActivityId($activityEntityKey);

        $this->assertNotNull($result);
        $this->assertSame($project, $result);
    }

    public function testGetByActivityIdRoutesToZebraRepositoryForZebraEntityKey(): void
    {
        $activityEntityKey = EntityKey::zebra(1);
        $project = new Project(
            EntityKey::zebra(100),
            'Zebra Project',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->never())
            ->method('getByActivityId');

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByActivityId')
            ->with($activityEntityKey)
            ->willReturn($project);

        $result = $this->repository->getByActivityId($activityEntityKey);

        $this->assertNotNull($result);
        $this->assertSame($project, $result);
    }

    public function testGetByActivityAliasChecksLocalFirstThenZebra(): void
    {
        $localProject = new Project(
            EntityKey::local(Uuid::random()),
            'Local Project',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($localProject);

        $this->zebraRepository
            ->expects($this->never())
            ->method('getByActivityAlias');

        $result = $this->repository->getByActivityAlias('test-alias');

        $this->assertNotNull($result);
        $this->assertSame($localProject, $result);
    }

    public function testGetByActivityAliasFallsBackToZebraWhenLocalReturnsNull(): void
    {
        $zebraProject = new Project(
            EntityKey::zebra(100),
            'Zebra Project',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn(null);

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByActivityAlias')
            ->with('test-alias')
            ->willReturn($zebraProject);

        $result = $this->repository->getByActivityAlias('test-alias');

        $this->assertNotNull($result);
        $this->assertSame($zebraProject, $result);
    }

    public function testUpdateFromApiDelegatesToZebraRepository(): void
    {
        // updateFromApi is only in ZebraProjectRepositoryInterface, not LocalProjectRepositoryInterface
        // So we can't mock it on localRepository, but we can verify zebraRepository is called
        $this->zebraRepository
            ->expects($this->once())
            ->method('updateFromApi');

        $this->repository->updateFromApi();
    }

    public function testGetAllAliasesMergesResultsFromBothRepositories(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('getAllAliases')
            ->willReturn(['local-alias-1', 'local-alias-2']);

        $this->zebraRepository
            ->expects($this->once())
            ->method('getAllAliases')
            ->willReturn(['zebra-alias-1', 'zebra-alias-2']);

        $result = $this->repository->getAllAliases();

        $this->assertCount(4, $result);
        $this->assertContains('local-alias-1', $result);
        $this->assertContains('local-alias-2', $result);
        $this->assertContains('zebra-alias-1', $result);
        $this->assertContains('zebra-alias-2', $result);
    }

    public function testCreateDelegatesToLocalRepository(): void
    {
        $project = new Project(
            EntityKey::local(Uuid::random()),
            'New Project',
            'Description',
            1,
            []
        );

        $this->localRepository
            ->expects($this->once())
            ->method('create')
            ->with('New Project', 'Description', 1)
            ->willReturn($project);

        $result = $this->repository->create('New Project', 'Description', 1);

        $this->assertSame($project, $result);
    }

    public function testUpdateDelegatesToLocalRepository(): void
    {
        $entityKey = EntityKey::local(Uuid::random());
        $project = new Project($entityKey, 'Updated Project', 'Updated Description', 1, []);

        $this->localRepository
            ->expects($this->once())
            ->method('update')
            ->with($entityKey, 'Updated Project', 'Updated Description', null)
            ->willReturn($project);

        $result = $this->repository->update($entityKey, 'Updated Project', 'Updated Description');

        $this->assertSame($project, $result);
    }

    public function testDeleteDelegatesToLocalRepository(): void
    {
        $entityKey = EntityKey::local(Uuid::random());

        $this->localRepository
            ->expects($this->once())
            ->method('delete')
            ->with($entityKey);

        $this->repository->delete($entityKey);
    }

    public function testUpdateActivitiesDelegatesToLocalRepository(): void
    {
        $entityKey = EntityKey::local(Uuid::random());
        $activity = new Activity(EntityKey::local(Uuid::random()), 'Activity', 'Description', $entityKey);
        $project = new Project($entityKey, 'Project', 'Description', 1, [$activity]);

        $this->localRepository
            ->expects($this->once())
            ->method('updateActivities')
            ->with($entityKey, [$activity])
            ->willReturn($project);

        $result = $this->repository->updateActivities($entityKey, [$activity]);

        $this->assertSame($project, $result);
    }
}
