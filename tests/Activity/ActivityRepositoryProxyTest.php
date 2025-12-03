<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Activity;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityRepository;
use Tcrawf\Zebra\Activity\LocalActivityRepositoryInterface;
use Tcrawf\Zebra\Activity\ZebraActivityRepositoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Uuid\Uuid;

class ActivityRepositoryProxyTest extends TestCase
{
    private LocalActivityRepositoryInterface&MockObject $localRepository;
    private ZebraActivityRepositoryInterface&MockObject $zebraRepository;
    private ActivityRepository $repository;

    protected function setUp(): void
    {
        $this->localRepository = $this->createMock(LocalActivityRepositoryInterface::class);
        $this->zebraRepository = $this->createMock(ZebraActivityRepositoryInterface::class);
        $this->repository = new ActivityRepository($this->localRepository, $this->zebraRepository);
    }

    public function testAllMergesResultsFromBothRepositories(): void
    {
        $localActivity = new Activity(
            EntityKey::local(Uuid::random()),
            'Local Activity',
            'Description',
            EntityKey::local(Uuid::random())
        );
        $zebraActivity = new Activity(
            EntityKey::zebra(2),
            'Zebra Activity',
            'Description',
            EntityKey::zebra(200)
        );

        $this->localRepository
            ->expects($this->once())
            ->method('all')
            ->with(true)
            ->willReturn([$localActivity]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('all')
            ->with(true)
            ->willReturn([$zebraActivity]);

        $result = $this->repository->all(true);

        $this->assertCount(2, $result);
        $this->assertSame(EntitySource::Local, $result[0]->entityKey->source);
        $this->assertSame(EntitySource::Zebra, $result[1]->entityKey->source);
    }

    public function testAllDefaultsToActiveOnly(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('all')
            ->with(true)
            ->willReturn([]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('all')
            ->with(true)
            ->willReturn([]);

        $this->repository->all();
    }

    public function testGetRoutesToLocalRepositoryForLocalEntityKey(): void
    {
        $entityKey = EntityKey::local(Uuid::random());
        $activity = new Activity($entityKey, 'Local Activity', 'Description', EntityKey::local(Uuid::random()));

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($entityKey)
            ->willReturn($activity);

        $this->zebraRepository
            ->expects($this->never())
            ->method('get');

        $result = $this->repository->get($entityKey);

        $this->assertNotNull($result);
        $this->assertSame($activity, $result);
    }

    public function testGetRoutesToZebraRepositoryForZebraEntityKey(): void
    {
        $entityKey = EntityKey::zebra(1);
        $activity = new Activity($entityKey, 'Zebra Activity', 'Description', EntityKey::zebra(100));

        $this->localRepository
            ->expects($this->never())
            ->method('get');

        $this->zebraRepository
            ->expects($this->once())
            ->method('get')
            ->with($entityKey)
            ->willReturn($activity);

        $result = $this->repository->get($entityKey);

        $this->assertNotNull($result);
        $this->assertSame($activity, $result);
    }

    public function testGetReturnsNullForUnknownSource(): void
    {
        // Create an entity key with an unknown source (this shouldn't happen in practice)
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

    public function testGetByAliasChecksLocalFirstThenZebra(): void
    {
        $localActivity = new Activity(
            EntityKey::local(Uuid::random()),
            'Local Activity',
            'Description',
            EntityKey::local(Uuid::random()),
            'test-alias'
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias', true)
            ->willReturn($localActivity);

        $this->zebraRepository
            ->expects($this->never())
            ->method('getByAlias');

        $result = $this->repository->getByAlias('test-alias', true);

        $this->assertNotNull($result);
        $this->assertSame($localActivity, $result);
    }

    public function testGetByAliasFallsBackToZebraWhenLocalReturnsNull(): void
    {
        $zebraActivity = new Activity(
            EntityKey::zebra(1),
            'Zebra Activity',
            'Description',
            EntityKey::zebra(100),
            'test-alias'
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias', true)
            ->willReturn(null);

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias', true)
            ->willReturn($zebraActivity);

        $result = $this->repository->getByAlias('test-alias', true);

        $this->assertNotNull($result);
        $this->assertSame($zebraActivity, $result);
    }

    public function testGetByAliasDefaultsToActiveOnly(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias', true)
            ->willReturn(null);

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias', true)
            ->willReturn(null);

        $result = $this->repository->getByAlias('test-alias');

        $this->assertNull($result);
    }

    public function testSearchByNameOrAliasMergesResultsFromBothRepositories(): void
    {
        $localActivity = new Activity(
            EntityKey::local(Uuid::random()),
            'Local Test Activity',
            'Description',
            EntityKey::local(Uuid::random())
        );
        $zebraActivity = new Activity(
            EntityKey::zebra(2),
            'Zebra Test Activity',
            'Description',
            EntityKey::zebra(200)
        );

        $this->localRepository
            ->expects($this->once())
            ->method('searchByNameOrAlias')
            ->with('test', true)
            ->willReturn([$localActivity]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('searchByNameOrAlias')
            ->with('test', true)
            ->willReturn([$zebraActivity]);

        $result = $this->repository->searchByNameOrAlias('test', true);

        $this->assertCount(2, $result);
    }

    public function testSearchByNameOrAliasDefaultsToActiveOnly(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('searchByNameOrAlias')
            ->with('test', true)
            ->willReturn([]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('searchByNameOrAlias')
            ->with('test', true)
            ->willReturn([]);

        $this->repository->searchByNameOrAlias('test');
    }

    public function testSearchByAliasMergesResultsFromBothRepositories(): void
    {
        $localActivity = new Activity(
            EntityKey::local(Uuid::random()),
            'Local Activity',
            'Description',
            EntityKey::local(Uuid::random()),
            'test-alias'
        );
        $zebraActivity = new Activity(
            EntityKey::zebra(2),
            'Zebra Activity',
            'Description',
            EntityKey::zebra(200),
            'test-alias'
        );

        $this->localRepository
            ->expects($this->once())
            ->method('searchByAlias')
            ->with('test')
            ->willReturn([$localActivity]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('searchByAlias')
            ->with('test')
            ->willReturn([$zebraActivity]);

        $result = $this->repository->searchByAlias('test');

        $this->assertCount(2, $result);
    }

    public function testCreateDelegatesToLocalRepository(): void
    {
        $projectEntityKey = EntityKey::local(Uuid::random());
        $activity = new Activity(
            EntityKey::local(Uuid::random()),
            'New Activity',
            'Description',
            $projectEntityKey
        );

        $this->localRepository
            ->expects($this->once())
            ->method('create')
            ->with('New Activity', 'Description', $projectEntityKey, null)
            ->willReturn($activity);

        $result = $this->repository->create('New Activity', 'Description', $projectEntityKey);

        $this->assertSame($activity, $result);
    }

    public function testCreateWithAliasDelegatesToLocalRepository(): void
    {
        $projectEntityKey = EntityKey::local(Uuid::random());
        $activity = new Activity(
            EntityKey::local(Uuid::random()),
            'New Activity',
            'Description',
            $projectEntityKey,
            'new-alias'
        );

        $this->localRepository
            ->expects($this->once())
            ->method('create')
            ->with('New Activity', 'Description', $projectEntityKey, 'new-alias')
            ->willReturn($activity);

        $result = $this->repository->create('New Activity', 'Description', $projectEntityKey, 'new-alias');

        $this->assertSame($activity, $result);
    }

    public function testUpdateDelegatesToLocalRepository(): void
    {
        $uuid = Uuid::random();
        $activity = new Activity(
            EntityKey::local(Uuid::random()),
            'Updated Activity',
            'Updated Description',
            EntityKey::local(Uuid::random())
        );

        $this->localRepository
            ->expects($this->once())
            ->method('update')
            ->with($uuid, 'Updated Activity', 'Updated Description', null)
            ->willReturn($activity);

        $result = $this->repository->update($uuid, 'Updated Activity', 'Updated Description');

        $this->assertSame($activity, $result);
    }

    public function testDeleteDelegatesToLocalRepository(): void
    {
        $uuid = Uuid::random();

        $this->localRepository
            ->expects($this->once())
            ->method('delete')
            ->with($uuid);

        $this->repository->delete($uuid);
    }
}
