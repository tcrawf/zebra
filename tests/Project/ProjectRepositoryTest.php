<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Project;

use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Cache\CacheFileStorageFactoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;
use Tcrawf\Zebra\Project\ProjectApiServiceInterface;
use Tcrawf\Zebra\Project\ZebraProjectRepository;
use Tcrawf\Zebra\Project\ProjectStatus;
use Tcrawf\Zebra\Tests\Helper\RepositoryTestCase;

class ProjectRepositoryTest extends RepositoryTestCase
{
    private ProjectApiServiceInterface&MockObject $apiService;
    private CacheFileStorageFactoryInterface&MockObject $cacheStorageFactory;
    private FileStorageInterface&MockObject $cacheStorage;
    private ZebraProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // Use unique cache filename for each test to ensure static cache isolation
        $cacheFilename = 'test_projects_' . uniqid() . '.json';

        $this->apiService = $this->createMock(ProjectApiServiceInterface::class);
        $this->cacheStorage = $this->createMock(FileStorageInterface::class);
        $this->cacheStorageFactory = $this->createMock(CacheFileStorageFactoryInterface::class);
        $this->cacheStorageFactory
            ->method('create')
            ->with($cacheFilename)
            ->willReturn($this->cacheStorage);

        $this->repository = new ZebraProjectRepository($this->apiService, $this->cacheStorageFactory, $cacheFilename);
    }

    public function testAllLoadsFromCache(): void
    {
        $projectData = [
            'id' => 100,
            'name' => 'Test Project',
            'description' => 'Description',
            'status' => 1,
            'activities' => [[
                'id' => 1,
                'name' => 'Activity 1',
                'description' => 'Description',
                'alias' => null
            ]]
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData]);

        $projects = $this->repository->all();

        $this->assertCount(1, $projects);
        $this->assertSame(EntitySource::Zebra, $projects[0]->entityKey->source);
        $this->assertSame(100, $projects[0]->entityKey->id);
        $this->assertEquals('Test Project', $projects[0]->name);
    }

    public function testAllFetchesFromApiWhenCacheEmpty(): void
    {
        $projectData = [
            100 => [
                'id' => 100,
                'name' => 'Test Project',
                'description' => 'Description',
                'status' => 1,
                'activities' => []
            ]
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($projectData);

        $this->cacheStorage
            ->expects($this->once())
            ->method('write')
            ->with($projectData);

        $projects = $this->repository->all();

        $this->assertCount(1, $projects);
    }

    public function testGet(): void
    {
        $activity = new Activity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $projectData = [
            'id' => 100,
            'name' => 'Test Project',
            'description' => '',
            'status' => 1,
            'activities' => [[
                'id' => 1,
                'name' => 'Activity 1',
                'description' => '',
                'alias' => null
            ]]
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData]);

        $project = $this->repository->get(EntityKey::zebra(100));

        $this->assertNotNull($project);
        $this->assertSame(EntitySource::Zebra, $project->entityKey->source);
        $this->assertSame(100, $project->entityKey->id);
    }

    public function testGetNotFound(): void
    {
        // get() calls all() which calls read()
        $this->cacheStorage
            ->method('read')
            ->willReturn([]);

        $project = $this->repository->get(EntityKey::zebra(999));

        $this->assertNull($project);
    }

    public function testGetByNameLikeStartsWith(): void
    {
        $projectData1 = [
            'id' => 100,
            'name' => 'Test Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];
        $projectData2 = [
            'id' => 200,
            'name' => 'Another Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData1, $projectData2]);

        $projects = $this->repository->getByNameLike('Test');

        $this->assertCount(1, $projects);
        $this->assertSame(EntitySource::Zebra, $projects[0]->entityKey->source);
        $this->assertSame(100, $projects[0]->entityKey->id);
    }

    public function testGetByNameLikeContains(): void
    {
        $projectData1 = [
            'id' => 100,
            'name' => 'Test Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];
        $projectData2 = [
            'id' => 200,
            'name' => 'Another Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData1, $projectData2]);

        $projects = $this->repository->getByNameLike('Project');

        $this->assertCount(2, $projects);
    }

    public function testGetByActivityId(): void
    {
        $activity = new Activity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $projectData = [
            'id' => 100,
            'name' => 'Test Project',
            'description' => '',
            'status' => 1,
            'activities' => [[
                'id' => 1,
                'name' => 'Activity 1',
                'description' => '',
                'alias' => null
            ]]
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData]);

        $project = $this->repository->getByActivityId(EntityKey::zebra(1));

        $this->assertNotNull($project);
        $this->assertSame(EntitySource::Zebra, $project->entityKey->source);
        $this->assertSame(100, $project->entityKey->id);
    }

    public function testGetByActivityAlias(): void
    {
        $projectData = [
            'id' => 100,
            'name' => 'Test Project',
            'description' => '',
            'status' => 1,
            'activities' => [[
                'id' => 1,
                'name' => 'Activity 1',
                'description' => '',
                'alias' => 'test-alias'
            ]]
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData]);

        $project = $this->repository->getByActivityAlias('test-alias');

        $this->assertNotNull($project);
        $this->assertSame(EntitySource::Zebra, $project->entityKey->source);
        $this->assertSame(100, $project->entityKey->id);
    }

    public function testUpdateFromApi(): void
    {
        $projectData = [
            100 => [
                'id' => 100,
                'name' => 'Updated Project',
                'description' => '',
                'status' => 1,
                'activities' => []
            ]
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($projectData);

        $this->cacheStorage
            ->expects($this->once())
            ->method('write')
            ->with($projectData);

        $this->repository->updateFromApi();
    }

    public function testAllDefaultsToActiveOnly(): void
    {
        $projectData1 = [
            'id' => 100,
            'name' => 'Active Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];
        $projectData2 = [
            'id' => 200,
            'name' => 'Inactive Project',
            'description' => '',
            'status' => 0,
            'activities' => []
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData1, $projectData2]);

        $projects = $this->repository->all();

        $this->assertCount(1, $projects);
        $this->assertSame(EntitySource::Zebra, $projects[0]->entityKey->source);
        $this->assertSame(100, $projects[0]->entityKey->id);
        $this->assertEquals(1, $projects[0]->status);
    }

    public function testAllWithEmptyArrayReturnsAllProjects(): void
    {
        $projectData1 = [
            'id' => 100,
            'name' => 'Active Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];
        $projectData2 = [
            'id' => 200,
            'name' => 'Inactive Project',
            'description' => '',
            'status' => 0,
            'activities' => []
        ];
        $projectData3 = [
            'id' => 300,
            'name' => 'Other Project',
            'description' => '',
            'status' => 2,
            'activities' => []
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData1, $projectData2, $projectData3]);

        $projects = $this->repository->all([]);

        $this->assertCount(3, $projects);
    }

    public function testAllWithSpecificStatusFiltersCorrectly(): void
    {
        $projectData1 = [
            'id' => 100,
            'name' => 'Active Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];
        $projectData2 = [
            'id' => 200,
            'name' => 'Inactive Project',
            'description' => '',
            'status' => 0,
            'activities' => []
        ];
        $projectData3 = [
            'id' => 300,
            'name' => 'Other Project',
            'description' => '',
            'status' => 2,
            'activities' => []
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData1, $projectData2, $projectData3]);

        $projects = $this->repository->all([ProjectStatus::Inactive]);

        $this->assertCount(1, $projects);
        $this->assertSame(EntitySource::Zebra, $projects[0]->entityKey->source);
        $this->assertSame(200, $projects[0]->entityKey->id);
        $this->assertEquals(0, $projects[0]->status);
    }

    public function testAllWithMultipleStatusesFiltersCorrectly(): void
    {
        $projectData1 = [
            'id' => 100,
            'name' => 'Active Project',
            'description' => '',
            'status' => 1,
            'activities' => []
        ];
        $projectData2 = [
            'id' => 200,
            'name' => 'Inactive Project',
            'description' => '',
            'status' => 0,
            'activities' => []
        ];
        $projectData3 = [
            'id' => 300,
            'name' => 'Other Project',
            'description' => '',
            'status' => 2,
            'activities' => []
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$projectData1, $projectData2, $projectData3]);

        $projects = $this->repository->all([ProjectStatus::Active, ProjectStatus::Other]);

        $this->assertCount(2, $projects);
        $statuses = array_map(static fn($project) => $project->status, $projects);
        $this->assertContains(1, $statuses);
        $this->assertContains(2, $statuses);
        $this->assertNotContains(0, $statuses);
    }

    public function testAllAlwaysFetchesAllFromApiRegardlessOfStatusFilter(): void
    {
        $projectData = [
            100 => [
                'id' => 100,
                'name' => 'Active Project',
                'description' => '',
                'status' => 1,
                'activities' => []
            ],
            200 => [
                'id' => 200,
                'name' => 'Inactive Project',
                'description' => '',
                'status' => 0,
                'activities' => []
            ]
        ];

        $this->cacheStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($projectData);

        $this->cacheStorage
            ->expects($this->once())
            ->method('write')
            ->with($projectData);

        // Request only active, but API should fetch all
        $projects = $this->repository->all([ProjectStatus::Active]);

        $this->assertCount(1, $projects);
        $this->assertSame(EntitySource::Zebra, $projects[0]->entityKey->source);
        $this->assertSame(100, $projects[0]->entityKey->id);
    }
}
