<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Project;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\LocalProjectRepository;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectStatus;
use Tcrawf\Zebra\Uuid\Uuid;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class LocalProjectRepositoryTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $testHomeDir;
    private string $originalHome;
    private LocalProjectRepository $repository;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('test');
        $this->testHomeDir = $this->root->url();
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->testHomeDir);
        $this->repository = new LocalProjectRepository('test-projects.json');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
        // Clear static cache
        $reflection = new \ReflectionClass(LocalProjectRepository::class);
        $property = $reflection->getProperty('projectCache');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    public function testCreate(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals('Test Project', $project->name);
        $this->assertEquals('Description', $project->description);
        $this->assertEquals(1, $project->status);
        $this->assertNotNull($project->entityKey);
        $this->assertEquals(EntitySource::Local, $project->entityKey->source);
    }

    public function testAllReturnsActiveProjectsByDefault(): void
    {
        $project1 = $this->repository->create('Active Project', 'Description', 1);
        $project2 = $this->repository->create('Inactive Project', 'Description', 0);

        $all = $this->repository->all();

        $this->assertCount(1, $all);
        $this->assertEquals('Active Project', $all[0]->name);
    }

    public function testAllWithEmptyStatusesReturnsAllProjects(): void
    {
        $project1 = $this->repository->create('Active Project', 'Description', 1);
        $project2 = $this->repository->create('Inactive Project', 'Description', 0);

        $all = $this->repository->all([]);

        $this->assertCount(2, $all);
    }

    public function testAllWithSpecificStatuses(): void
    {
        $project1 = $this->repository->create('Active Project', 'Description', 1);
        $project2 = $this->repository->create('Inactive Project', 'Description', 0);
        $project3 = $this->repository->create('Other Project', 'Description', 2);

        $inactive = $this->repository->all([ProjectStatus::Inactive]);

        $this->assertCount(1, $inactive);
        $this->assertEquals('Inactive Project', $inactive[0]->name);
    }

    public function testGet(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);
        $entityKey = $project->entityKey;

        $result = $this->repository->get($entityKey);

        $this->assertNotNull($result);
        $this->assertEquals($project->name, $result->name);
    }

    public function testGetReturnsNullForNonLocalEntityKey(): void
    {
        $zebraKey = EntityKey::zebra(100);
        $result = $this->repository->get($zebraKey);
        $this->assertNull($result);
    }

    public function testGetReturnsNullForNonExistentProject(): void
    {
        $uuid = Uuid::random();
        $localKey = EntityKey::local($uuid);
        $result = $this->repository->get($localKey);
        $this->assertNull($result);
    }

    public function testGetByNameLike(): void
    {
        $this->repository->create('Test Project', 'Description', 1);
        $this->repository->create('Another Test', 'Description', 1);
        $this->repository->create('Different Project', 'Description', 1);

        $results = $this->repository->getByNameLike('Test');

        $this->assertCount(2, $results);
        $names = array_map(fn($p) => $p->name, $results);
        $this->assertContains('Test Project', $names);
        $this->assertContains('Another Test', $names);
    }

    public function testGetByNameLikePrioritizesStartsWith(): void
    {
        $this->repository->create('Test Project', 'Description', 1);
        $this->repository->create('Another Test', 'Description', 1);

        $results = $this->repository->getByNameLike('Test');

        // "Test Project" should come first (starts with)
        $this->assertGreaterThanOrEqual(1, count($results));
        if (count($results) > 0) {
            $this->assertEquals('Test Project', $results[0]->name);
        }
    }

    public function testUpdate(): void
    {
        $project = $this->repository->create('Original Name', 'Description', 1);
        $entityKey = $project->entityKey;

        $updated = $this->repository->update($entityKey, 'Updated Name', 'Updated Description', 0);

        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals('Updated Description', $updated->description);
        $this->assertEquals(0, $updated->status);
    }

    public function testUpdateWithPartialData(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);
        $entityKey = $project->entityKey;

        $updated = $this->repository->update($entityKey, 'Updated Name');

        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals('Description', $updated->description); // Unchanged
        $this->assertEquals(1, $updated->status); // Unchanged
    }

    public function testUpdateThrowsExceptionForNonLocalEntityKey(): void
    {
        $zebraKey = EntityKey::zebra(100);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity key must be from Local source');

        $this->repository->update($zebraKey, 'New Name');
    }

    public function testUpdateThrowsExceptionForNonExistentProject(): void
    {
        $uuid = Uuid::random();
        $localKey = EntityKey::local($uuid);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->repository->update($localKey, 'New Name');
    }

    public function testDelete(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);
        $entityKey = $project->entityKey;

        $this->repository->delete($entityKey);

        $result = $this->repository->get($entityKey);
        $this->assertNull($result);
    }

    public function testDeleteThrowsExceptionForNonLocalEntityKey(): void
    {
        $zebraKey = EntityKey::zebra(100);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity key must be from Local source');

        $this->repository->delete($zebraKey);
    }

    public function testDeleteThrowsExceptionForNonExistentProject(): void
    {
        $uuid = Uuid::random();
        $localKey = EntityKey::local($uuid);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->repository->delete($localKey);
    }

    public function testGetByActivityId(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);
        $activity = new Activity(
            EntityKey::local(Uuid::random()),
            'Test Activity',
            'Description',
            $project->entityKey
        );

        // Update project with activity
        $updatedProject = new Project(
            $project->entityKey,
            $project->name,
            $project->description,
            $project->status,
            [$activity]
        );
        $this->repository->updateActivities($project->entityKey, [$activity]);

        $result = $this->repository->getByActivityId($activity->entityKey);

        $this->assertNotNull($result);
        $this->assertEquals($project->name, $result->name);
    }

    public function testGetByActivityAlias(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);
        $activity = new Activity(
            EntityKey::local(Uuid::random()),
            'Test Activity',
            'Description',
            $project->entityKey,
            'test-alias'
        );

        $this->repository->updateActivities($project->entityKey, [$activity]);

        $result = $this->repository->getByActivityAlias('test-alias');

        $this->assertNotNull($result);
        $this->assertEquals($project->name, $result->name);
    }

    public function testGetAllAliases(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);
        $activity1 = new Activity(
            EntityKey::local(Uuid::random()),
            'Activity 1',
            'Description',
            $project->entityKey,
            'alias1'
        );
        $activity2 = new Activity(
            EntityKey::local(Uuid::random()),
            'Activity 2',
            'Description',
            $project->entityKey,
            'alias2'
        );

        $this->repository->updateActivities($project->entityKey, [$activity1, $activity2]);

        $aliases = $this->repository->getAllAliases();

        $this->assertCount(2, $aliases);
        $this->assertContains('alias1', $aliases);
        $this->assertContains('alias2', $aliases);
    }

    public function testUpdateActivities(): void
    {
        $project = $this->repository->create('Test Project', 'Description', 1);
        $activity = new Activity(
            EntityKey::local(Uuid::random()),
            'Test Activity',
            'Description',
            $project->entityKey
        );

        $updated = $this->repository->updateActivities($project->entityKey, [$activity]);

        $this->assertCount(1, $updated->activities);
        $this->assertEquals($activity->name, $updated->activities[0]->name);
    }
}
