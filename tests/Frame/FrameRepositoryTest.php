<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Frame;

use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;
use Tcrawf\Zebra\Frame\FrameFileStorageFactoryInterface;
use Tcrawf\Zebra\Frame\FrameRepository;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Tests\Helper\RepositoryTestCase;
use Tcrawf\Zebra\Tests\Helper\TestEntityFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class FrameRepositoryTest extends RepositoryTestCase
{
    private FrameFileStorageFactoryInterface&MockObject $storageFactory;
    private FileStorageInterface&MockObject $storage;
    private FrameRepository $repository;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        // Default: return storage for test_frames.json, individual tests can override
        $this->storageFactory
            ->method('create')
            ->willReturnCallback(function ($filename) {
                return $this->storage;
            });

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->activity = TestEntityFactory::createActivity();
        $this->role = TestEntityFactory::createRole();
    }

    public function testSave(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Description'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->storage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($uuid) {
                return isset($data[$uuid->getHex()]);
            }));

        $this->repository->save($frame);
    }

    public function testSaveActiveFrameThrowsException(): void
    {
        $uuid = Uuid::random();
        $frame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot save a frame that does not have a stop datetime');

        $this->repository->save($frame);
    }

    public function testAll(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Description'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $frame->toArray()]);

        $frames = $this->repository->all();

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid->getHex(), $frames[0]->uuid);
    }

    public function testGet(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Description'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $frame->toArray()]);

        $result = $this->repository->get($uuid->getHex());

        $this->assertNotNull($result);
        $this->assertEquals($uuid->getHex(), $result->uuid);
    }

    public function testGetNotFound(): void
    {
        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $result = $this->repository->get('non-existent-uuid');

        $this->assertNull($result);
    }

    public function testGetByDateRange(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subDays(2);
        $start2 = Carbon::now()->subHour();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $from = Carbon::now()->subDay();
        $frames = $this->repository->getByDateRange($from);

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid2->getHex(), $frames[0]->uuid);
    }

    public function testGetByDateRangeWithTo(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subDays(2);
        $start2 = Carbon::now()->subHour();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $from = Carbon::now()->subDays(3);
        $to = Carbon::now()->subDay();
        $frames = $this->repository->getByDateRange($from, $to);

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid1->getHex(), $frames[0]->uuid);
    }

    public function testFilterByProjectIds(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $activity2 = TestEntityFactory::createActivity(EntityKey::zebra(2), 'Activity 2', '', EntityKey::zebra(200));
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            Carbon::now()->subHour(),
            Carbon::now(),
            $activity1,
            false,
            $this->role
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $activity2,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $frames = $this->repository->filter(projectIds: [100]);

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid1->getHex(), $frames[0]->uuid);
    }

    public function testFilterByIssueKeys(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role,
            'ABC-123'
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role,
            'XYZ-456'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $frames = $this->repository->filter(issueKeys: ['ABC-123']);

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid1->getHex(), $frames[0]->uuid);
    }

    public function testSaveCurrent(): void
    {
        $uuid = Uuid::random();
        $frame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->exactly(2))
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        // getCurrent() is called first, returns null (no existing current frame)
        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        // Then saveCurrent() writes the frame
        $currentStorage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($uuid) {
                return isset($data['uuid']) && $data['uuid'] === $uuid->getHex();
            }));

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->repository->saveCurrent($frame);
    }

    public function testSaveCurrentWithActiveFrameThrowsException(): void
    {
        $uuid = Uuid::random();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot save a frame with a stop datetime as the current frame');

        $this->repository->saveCurrent($frame);
    }

    public function testGetCurrent(): void
    {
        $uuid = Uuid::random();
        $frame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->once())
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($frame->toArray());

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $result = $this->repository->getCurrent();

        $this->assertNotNull($result);
        $this->assertEquals($uuid->getHex(), $result->uuid);
    }

    public function testGetCurrentNotFound(): void
    {
        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->once())
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $result = $this->repository->getCurrent();

        $this->assertNull($result);
    }

    public function testCompleteCurrent(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $currentFrame = TestEntityFactory::createActiveFrame($uuid, $startTime, $this->activity, false, $this->role);

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $frameStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->method('create')
            ->willReturnCallback(function ($filename) use ($currentStorage, $frameStorage) {
                if ($filename === 'current_frame.json') {
                    return $currentStorage;
                }
                return $frameStorage;
            });

        // getCurrent() is called first
        $currentStorage
            ->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($currentFrame->toArray());

        // save() is called to save the completed frame
        $frameStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $frameStorage
            ->expects($this->once())
            ->method('write');

        // clearCurrent() is called at the end (also calls exists())
        $currentStorage
            ->expects($this->once())
            ->method('write')
            ->with([]);

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $completed = $this->repository->completeCurrent();

        $this->assertFalse($completed->isActive());
        $this->assertNotNull($completed->stopTime);
    }

    public function testCompleteCurrentThrowsExceptionWhenNoCurrentFrame(): void
    {
        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->once())
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        // getCurrent() returns null when exists() is false
        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No current frame exists to complete');

        $this->repository->completeCurrent();
    }

    public function testClearCurrent(): void
    {
        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->once())
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('write')
            ->with([]);

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->repository->clearCurrent();
    }

    public function testGetLastUsedRoleForActivity(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $activity2 = TestEntityFactory::createActivity(EntityKey::zebra(2), 'Activity 2', '', EntityKey::zebra(200));
        $role1 = TestEntityFactory::createRole(1, null, 'Developer');
        $role2 = TestEntityFactory::createRole(2, null, 'Manager');
        $role3 = TestEntityFactory::createRole(3, null, 'Tester');

        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $uuid3 = Uuid::random();
        $start1 = Carbon::now()->subDays(3);
        $start2 = Carbon::now()->subDay();
        $start3 = Carbon::now()->subHour();

        // Activity 1 frames with different roles
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $activity1,
            false,
            $role1
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $activity1,
            false,
            $role2
        );
        // Activity 2 frame
        $frame3 = TestEntityFactory::createFrame(
            $uuid3,
            $start3,
            $start3->copy()->addHour(),
            $activity2,
            false,
            $role3
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray(),
                $uuid3->getHex() => $frame3->toArray()
            ]);

        $result = $this->repository->getLastUsedRoleForActivity($activity1);

        $this->assertNotNull($result);
        $this->assertEquals($role2->id, $result->id);
        $this->assertEquals('Manager', $result->name);
    }

    public function testGetLastUsedRoleForActivityReturnsNullWhenNoFrames(): void
    {
        $activity = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $result = $this->repository->getLastUsedRoleForActivity($activity);

        $this->assertNull($result);
    }

    public function testGetLastUsedRoleForActivityIgnoresActiveFrames(): void
    {
        $activity = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $role1 = TestEntityFactory::createRole(1, null, 'Developer');
        $role2 = TestEntityFactory::createRole(2, null, 'Manager');

        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subDays(2);
        $start2 = Carbon::now()->subHour();

        // Completed frame with role1
        $frame1 = TestEntityFactory::createFrame($uuid1, $start1, $start1->copy()->addHour(), $activity, false, $role1);
        // Active frame with role2 (should be ignored)
        $frame2 = TestEntityFactory::createActiveFrame($uuid2, $start2, $activity, false, $role2);

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $result = $this->repository->getLastUsedRoleForActivity($activity);

        $this->assertNotNull($result);
        $this->assertEquals($role1->id, $result->id);
        $this->assertEquals('Developer', $result->name);
    }

    public function testGetLastUsedRoleForActivityReturnsMostRecent(): void
    {
        $activity = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $role1 = TestEntityFactory::createRole(1, null, 'Developer');
        $role2 = TestEntityFactory::createRole(2, null, 'Manager');
        $role3 = TestEntityFactory::createRole(3, null, 'Tester');

        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $uuid3 = Uuid::random();
        $start1 = Carbon::now()->subDays(5);
        $start2 = Carbon::now()->subDays(2);
        $start3 = Carbon::now()->subHour();

        // Multiple frames with different roles, most recent should be returned
        $frame1 = TestEntityFactory::createFrame($uuid1, $start1, $start1->copy()->addHour(), $activity, false, $role1);
        $frame2 = TestEntityFactory::createFrame($uuid2, $start2, $start2->copy()->addHour(), $activity, false, $role2);
        $frame3 = TestEntityFactory::createFrame($uuid3, $start3, $start3->copy()->addHour(), $activity, false, $role3);

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray(),
                $uuid3->getHex() => $frame3->toArray()
            ]);

        $result = $this->repository->getLastUsedRoleForActivity($activity);

        $this->assertNotNull($result);
        $this->assertEquals($role3->id, $result->id);
        $this->assertEquals('Tester', $result->name);
    }

    public function testGetLastActivityForIssueKeys(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $activity2 = TestEntityFactory::createActivity(EntityKey::zebra(2), 'Activity 2', '', EntityKey::zebra(100));
        $role = TestEntityFactory::createRole(1, null, 'Developer');

        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subDays(2);
        $start2 = Carbon::now()->subHour();

        // Frame with ABC-123 and DEF-456
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $activity1,
            false,
            $role,
            'ABC-123 DEF-456'
        );
        // More recent frame with same issue keys (different order)
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $activity2,
            false,
            $role,
            'DEF-456 ABC-123'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $result = $this->repository->getLastActivityForIssueKeys(['ABC-123', 'DEF-456']);

        $this->assertNotNull($result);
        $this->assertEquals($activity2->entityKey->toString(), $result->entityKey->toString());
        $this->assertEquals('Activity 2', $result->name);
    }

    public function testGetLastActivityForIssueKeysReturnsNullWhenNoFrames(): void
    {
        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $result = $this->repository->getLastActivityForIssueKeys(['ABC-123', 'DEF-456']);

        $this->assertNull($result);
    }

    public function testGetLastActivityForIssueKeysReturnsNullWhenEmptyIssueKeys(): void
    {
        $this->storage
            ->expects($this->never())
            ->method('read');

        $result = $this->repository->getLastActivityForIssueKeys([]);

        $this->assertNull($result);
    }

    public function testGetLastActivityForIssueKeysIgnoresActiveFrames(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $activity2 = TestEntityFactory::createActivity(EntityKey::zebra(2), 'Activity 2', '', EntityKey::zebra(100));
        $role = TestEntityFactory::createRole(1, null, 'Developer');

        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subDays(2);
        $start2 = Carbon::now()->subHour();

        // Completed frame with ABC-123
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $activity1,
            false,
            $role,
            'ABC-123'
        );
        // Active frame with ABC-123 (should be ignored)
        $frame2 = TestEntityFactory::createActiveFrame($uuid2, $start2, $activity2, false, $role, 'ABC-123');

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $result = $this->repository->getLastActivityForIssueKeys(['ABC-123']);

        $this->assertNotNull($result);
        $this->assertEquals($activity1->entityKey->toString(), $result->entityKey->toString());
        $this->assertEquals('Activity 1', $result->name);
    }

    public function testGetLastActivityForIssueKeysReturnsMostRecent(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $activity2 = TestEntityFactory::createActivity(EntityKey::zebra(2), 'Activity 2', '', EntityKey::zebra(100));
        $activity3 = TestEntityFactory::createActivity(EntityKey::zebra(3), 'Activity 3', '', EntityKey::zebra(100));
        $role = TestEntityFactory::createRole(1, null, 'Developer');

        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $uuid3 = Uuid::random();
        $start1 = Carbon::now()->subDays(3);
        $start2 = Carbon::now()->subDays(2);
        $start3 = Carbon::now()->subHour();

        // Oldest frame
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $activity1,
            false,
            $role,
            'ABC-123'
        );
        // Middle frame
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $activity2,
            false,
            $role,
            'ABC-123'
        );
        // Most recent frame
        $frame3 = TestEntityFactory::createFrame(
            $uuid3,
            $start3,
            $start3->copy()->addHour(),
            $activity3,
            false,
            $role,
            'ABC-123'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray(),
                $uuid3->getHex() => $frame3->toArray()
            ]);

        $result = $this->repository->getLastActivityForIssueKeys(['ABC-123']);

        $this->assertNotNull($result);
        $this->assertEquals($activity3->entityKey->toString(), $result->entityKey->toString());
        $this->assertEquals('Activity 3', $result->name);
    }

    public function testGetLastActivityForIssueKeysIgnoresFramesWithNoIssueKeys(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $activity2 = TestEntityFactory::createActivity(EntityKey::zebra(2), 'Activity 2', '', EntityKey::zebra(100));
        $role = TestEntityFactory::createRole(1, null, 'Developer');

        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subDays(2);
        $start2 = Carbon::now()->subHour();

        // Frame with no issue keys
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $activity1,
            false,
            $role,
            'No issue keys'
        );
        // Frame with ABC-123
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $activity2,
            false,
            $role,
            'ABC-123'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $result = $this->repository->getLastActivityForIssueKeys(['ABC-123']);

        $this->assertNotNull($result);
        $this->assertEquals($activity2->entityKey->toString(), $result->entityKey->toString());
        $this->assertEquals('Activity 2', $result->name);
    }

    public function testGetLastActivityForIssueKeysMatchesOrderIndependent(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $role = TestEntityFactory::createRole(1, null, 'Developer');

        $uuid1 = Uuid::random();
        $start1 = Carbon::now()->subHour();

        // Frame with ABC-123 and DEF-456
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $activity1,
            false,
            $role,
            'ABC-123 DEF-456'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray()
            ]);

        // Search with different order
        $result = $this->repository->getLastActivityForIssueKeys(['DEF-456', 'ABC-123']);

        $this->assertNotNull($result);
        $this->assertEquals($activity1->entityKey->toString(), $result->entityKey->toString());
        $this->assertEquals('Activity 1', $result->name);
    }

    public function testUpdate(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Original description'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $frame->toArray()]);

        $updatedFrame = TestEntityFactory::createFrame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Updated description'
        );

        $this->storage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($uuid) {
                return isset($data[$uuid->getHex()]) && $data[$uuid->getHex()]['desc'] === 'Updated description';
            }));

        $this->repository->update($updatedFrame);
    }

    public function testUpdateThrowsExceptionWhenFrameNotFound(): void
    {
        $uuid = Uuid::random();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot update frame: frame with UUID '{$uuid->getHex()}' does not exist");

        $this->repository->update($frame);
    }

    public function testRemove(): void
    {
        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Description'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $frame->toArray()]);

        $this->storage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($uuid) {
                return !isset($data[$uuid->getHex()]);
            }));

        $this->repository->remove($uuid->getHex());
    }

    public function testRemoveThrowsExceptionWhenFrameNotFound(): void
    {
        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot remove frame: frame with UUID 'non-existent-uuid' does not exist");

        $this->repository->remove('non-existent-uuid');
    }

    public function testFilterWithIgnoreProjectIds(): void
    {
        $activity1 = TestEntityFactory::createActivity(EntityKey::zebra(1), 'Activity 1', '', EntityKey::zebra(100));
        $activity2 = TestEntityFactory::createActivity(EntityKey::zebra(2), 'Activity 2', '', EntityKey::zebra(200));
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            Carbon::now()->subHour(),
            Carbon::now(),
            $activity1,
            false,
            $this->role
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $activity2,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $frames = $this->repository->filter(ignoreProjectIds: [100]);

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid2->getHex(), $frames[0]->uuid);
    }

    public function testFilterWithIgnoreIssueKeys(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role,
            'ABC-123'
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role,
            'XYZ-456'
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $frames = $this->repository->filter(ignoreIssueKeys: ['ABC-123']);

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid2->getHex(), $frames[0]->uuid);
    }

    public function testFilterWithDateRange(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subDays(2);
        $start2 = Carbon::now()->subHour();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $frames = $this->repository->filter(from: $from, to: $to);

        $this->assertCount(1, $frames);
        $this->assertEquals($uuid2->getHex(), $frames[0]->uuid);
    }

    public function testFilterWithIncludePartialFrames(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        // Frame that starts before range but ends within range
        $start1 = Carbon::now()->subDays(2);
        $stop1 = Carbon::now()->subMinutes(30);
        // Frame completely within range
        $start2 = Carbon::now()->subHour();
        $stop2 = Carbon::now()->subMinutes(30);
        $frame1 = TestEntityFactory::createFrame($uuid1, $start1, $stop1, $this->activity, false, $this->role);
        $frame2 = TestEntityFactory::createFrame($uuid2, $start2, $stop2, $this->activity, false, $this->role);

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $frames = $this->repository->filter(from: $from, to: $to, includePartialFrames: true);

        // Both frames should be included (frame1 overlaps, frame2 is within)
        $this->assertCount(2, $frames);
    }

    public function testFilterWithActiveFrameInDateRange(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subHour();
        $start2 = Carbon::now()->subMinutes(30);
        $frame1 = TestEntityFactory::createFrame($uuid1, $start1, Carbon::now(), $this->activity, false, $this->role);
        $frame2 = TestEntityFactory::createActiveFrame($uuid2, $start2, $this->activity, false, $this->role);

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $frame1->toArray(),
                $uuid2->getHex() => $frame2->toArray()
            ]);

        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $frames = $this->repository->filter(from: $from, to: $to, includePartialFrames: true);

        // Active frame should be included when includePartialFrames is true
        $this->assertGreaterThanOrEqual(1, count($frames));
    }

    public function testGetByDateRangeWithStringDates(): void
    {
        $uuid = Uuid::random();
        $start = Carbon::now()->subHour();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            $start,
            $start->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $frame->toArray()]);

        $from = Carbon::now()->subDay()->format('Y-m-d H:i:s');
        $frames = $this->repository->getByDateRange($from);

        $this->assertCount(1, $frames);
    }

    public function testGetByDateRangeWithTimestamp(): void
    {
        $uuid = Uuid::random();
        $start = Carbon::now()->subHour();
        $frame = TestEntityFactory::createFrame(
            $uuid,
            $start,
            $start->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $frame->toArray()]);

        $from = Carbon::now()->subDay()->timestamp;
        $frames = $this->repository->getByDateRange($from);

        $this->assertCount(1, $frames);
    }

    public function testUpdateCurrentFrameWhenStillActive(): void
    {
        $uuid = Uuid::random();
        $currentFrame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );
        $updatedFrame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role,
            'Updated description'
        );

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $frameStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->method('create')
            ->willReturnCallback(function ($filename) use ($currentStorage, $frameStorage) {
                if ($filename === 'current_frame.json') {
                    return $currentStorage;
                }
                return $frameStorage;
            });

        $frameStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $currentFrame->toArray()]);

        $frameStorage
            ->expects($this->once())
            ->method('write');

        $currentStorage
            ->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->exactly(2))
            ->method('read')
            ->willReturn($currentFrame->toArray());

        $currentStorage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($uuid) {
                return isset($data['uuid']) && $data['uuid'] === $uuid->getHex();
            }));

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->repository->update($updatedFrame);
    }

    public function testUpdateCurrentFrameWhenNoLongerActive(): void
    {
        $uuid = Uuid::random();
        $currentFrame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );
        $updatedFrame = TestEntityFactory::createFrame(
            $uuid,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role,
            'Updated description'
        );

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $frameStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->method('create')
            ->willReturnCallback(function ($filename) use ($currentStorage, $frameStorage) {
                if ($filename === 'current_frame.json') {
                    return $currentStorage;
                }
                return $frameStorage;
            });

        $frameStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $currentFrame->toArray()]);

        $frameStorage
            ->expects($this->once())
            ->method('write');

        $currentStorage
            ->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($currentFrame->toArray());

        $currentStorage
            ->expects($this->once())
            ->method('write')
            ->with([]);

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->repository->update($updatedFrame);
    }

    public function testRemoveCurrentFrame(): void
    {
        $uuid = Uuid::random();
        $currentFrame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $frameStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->method('create')
            ->willReturnCallback(function ($filename) use ($currentStorage, $frameStorage) {
                if ($filename === 'current_frame.json') {
                    return $currentStorage;
                }
                return $frameStorage;
            });

        $frameStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn([$uuid->getHex() => $currentFrame->toArray()]);

        $frameStorage
            ->expects($this->once())
            ->method('write')
            ->with([]);

        $currentStorage
            ->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($currentFrame->toArray());

        $currentStorage
            ->expects($this->once())
            ->method('write')
            ->with([]);

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->repository->remove($uuid->getHex());
    }

    public function testSaveCurrentWithFutureStartTimeThrowsException(): void
    {
        $uuid = Uuid::random();
        $futureTime = Carbon::now()->addHour();
        $frame = TestEntityFactory::createActiveFrame($uuid, $futureTime, $this->activity, false, $this->role);

        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot save a frame with a start datetime later than the current time');

        $this->repository->saveCurrent($frame);
    }

    public function testSaveCurrentWithDifferentExistingFrameThrowsException(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $existingFrame = TestEntityFactory::createActiveFrame(
            $uuid1,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );
        $newFrame = TestEntityFactory::createActiveFrame(
            $uuid2,
            Carbon::now()->subMinutes(30),
            $this->activity,
            false,
            $this->role
        );

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->once())
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($existingFrame->toArray());

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot save a current frame: a different current frame already exists');

        $this->repository->saveCurrent($newFrame);
    }

    public function testCompleteCurrentWithFutureStopTimeThrowsException(): void
    {
        $uuid = Uuid::random();
        $currentFrame = TestEntityFactory::createActiveFrame(
            $uuid,
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role
        );

        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->once())
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn($currentFrame->toArray());

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot complete a frame with a stop datetime later than the current time');

        $futureTime = Carbon::now()->addHour();
        $this->repository->completeCurrent($futureTime);
    }

    public function testAllSkipsInvalidFrames(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $validFrame = TestEntityFactory::createFrame(
            $uuid1,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity,
            false,
            $this->role
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid1->getHex() => $validFrame->toArray(),
                $uuid2->getHex() => ['invalid' => 'data']
            ]);

        $frames = $this->repository->all();

        // Should only return valid frame
        $this->assertCount(1, $frames);
        $this->assertEquals($uuid1->getHex(), $frames[0]->uuid);
    }

    public function testGetReturnsNullForInvalidFrame(): void
    {
        $uuid = Uuid::random();

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $uuid->getHex() => ['invalid' => 'data']
            ]);

        $result = $this->repository->get($uuid->getHex());

        $this->assertNull($result);
    }

    public function testGetCurrentReturnsNullForInvalidFrame(): void
    {
        $currentStorage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->expects($this->once())
            ->method('create')
            ->with('current_frame.json')
            ->willReturn($currentStorage);

        $currentStorage
            ->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $currentStorage
            ->expects($this->once())
            ->method('read')
            ->willReturn(['invalid' => 'data']);

        $this->repository = new FrameRepository($this->storageFactory, 'test_frames.json');
        $result = $this->repository->getCurrent();

        $this->assertNull($result);
    }
}
