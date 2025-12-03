<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepository;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\TimesheetFileStorageFactoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class LocalTimesheetRepositoryTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $testHomeDir;
    private TimesheetFileStorageFactoryInterface&MockObject $storageFactory;
    private FileStorageInterface&MockObject $storage;
    private LocalTimesheetRepository $repository;
    private Activity $activity;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('test');
        $this->testHomeDir = $this->root->url();
        putenv('HOME=' . $this->testHomeDir);

        $this->storage = $this->createMock(FileStorageInterface::class);
        $this->storageFactory = $this->createMock(TimesheetFileStorageFactoryInterface::class);
        $this->storageFactory
            ->method('create')
            ->willReturnCallback(function ($filename) {
                return $this->storage;
            });

        $this->repository = new LocalTimesheetRepository($this->storageFactory, 'test_timesheets.json');
        $this->activity = new Activity(
            EntityKey::zebra(123),
            'Test Activity',
            'Activity Description',
            EntityKey::zebra(100),
            'activity-123'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    private Role $role;

    private function createActivity(int $activityId, int $projectId, string $alias = null): Activity
    {
        return new Activity(
            EntityKey::zebra($activityId),
            "Activity {$activityId}",
            "Description {$activityId}",
            EntityKey::zebra($projectId),
            $alias
        );
    }

    protected function tearDown(): void
    {
        putenv('HOME');
    }

    public function testSave(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->storage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($timesheet) {
                return isset($data[$timesheet->uuid]);
            }));

        $this->repository->save($timesheet);
    }

    public function testAll(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $date = Carbon::now()->startOfDay();

        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $activity2 = $this->createActivity(456, 200, 'activity-456');
        $timesheet2 = TimesheetFactory::create(
            $activity2,
            'Description 2',
            null,
            2.0,
            $date,
            new Role(2, null, 'Manager', 'Manager', 'employee', 'active'),
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet1->uuid => $timesheet1->toArray(),
                $timesheet2->uuid => $timesheet2->toArray(),
            ]);

        $result = $this->repository->all();

        $this->assertCount(2, $result);
        $this->assertEquals($timesheet1->uuid, $result[0]->uuid);
        $this->assertEquals($timesheet2->uuid, $result[1]->uuid);
    }

    public function testAllWithEmptyStorage(): void
    {
        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $result = $this->repository->all();

        $this->assertEmpty($result);
    }

    public function testGet(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet->uuid => $timesheet->toArray(),
            ]);

        $result = $this->repository->get($timesheet->uuid);

        $this->assertNotNull($result);
        $this->assertEquals($timesheet->uuid, $result->uuid);
        $this->assertEquals(100, $result->getProjectId());
    }

    public function testGetWithNonExistentUuid(): void
    {
        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $result = $this->repository->get('nonexistent');

        $this->assertNull($result);
    }

    public function testGetByZebraId(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $date = Carbon::now()->startOfDay();

        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [],
            42
        );

        $activity2 = $this->createActivity(456, 200, 'activity-456');
        $timesheet2 = TimesheetFactory::create(
            $activity2,
            'Description 2',
            null,
            2.0,
            $date,
            new Role(2, null, 'Manager', 'Manager', 'employee', 'active'),
            false,
            [],
            99
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet1->uuid => $timesheet1->toArray(),
                $timesheet2->uuid => $timesheet2->toArray(),
            ]);

        $result = $this->repository->getByZebraId(42);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result->zebraId);
        $this->assertEquals($timesheet1->uuid, $result->uuid);
    }

    public function testGetByZebraIdWithNonExistentId(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [],
            42
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet->uuid => $timesheet->toArray(),
            ]);

        $result = $this->repository->getByZebraId(999);

        $this->assertNull($result);
    }

    public function testGetByDateRange(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $uuid3 = Uuid::random();

        $date1 = Carbon::create(2024, 1, 10);
        $date2 = Carbon::create(2024, 1, 15);
        $date3 = Carbon::create(2024, 1, 20);

        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date1,
            $this->role,
            false,
            []
        );

        $activity2 = $this->createActivity(456, 200, 'activity-456');
        $timesheet2 = TimesheetFactory::create(
            $activity2,
            'Description 2',
            null,
            2.0,
            $date2,
            new Role(2, null, 'Manager', 'Manager', 'employee', 'active'),
            false,
            []
        );

        $activity3 = $this->createActivity(789, 300, 'activity-789');
        $timesheet3 = TimesheetFactory::create(
            $activity3,
            'Description 3',
            null,
            3.0,
            $date3,
            new Role(3, null, 'Lead', 'Lead', 'employee', 'active'),
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet1->uuid => $timesheet1->toArray(),
                $timesheet2->uuid => $timesheet2->toArray(),
                $timesheet3->uuid => $timesheet3->toArray(),
            ]);

        $from = Carbon::create(2024, 1, 12);
        $to = Carbon::create(2024, 1, 18);

        $result = $this->repository->getByDateRange($from, $to);

        $this->assertCount(1, $result);
        $this->assertEquals($timesheet2->uuid, $result[0]->uuid);
    }

    public function testGetByDateRangeWithOnlyFrom(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();

        $date1 = Carbon::create(2024, 1, 10);
        $date2 = Carbon::create(2024, 1, 15);

        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date1,
            $this->role,
            false,
            []
        );

        $activity2 = $this->createActivity(456, 200, 'activity-456');
        $timesheet2 = TimesheetFactory::create(
            $activity2,
            'Description 2',
            null,
            2.0,
            $date2,
            new Role(2, null, 'Manager', 'Manager', 'employee', 'active'),
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet1->uuid => $timesheet1->toArray(),
                $timesheet2->uuid => $timesheet2->toArray(),
            ]);

        $from = Carbon::create(2024, 1, 12);

        $result = $this->repository->getByDateRange($from);

        $this->assertCount(1, $result);
        $this->assertEquals($timesheet2->uuid, $result[0]->uuid);
    }

    public function testGetByFrameUuids(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $uuid3 = Uuid::random();
        $date = Carbon::now()->startOfDay();

        $frameUuid1 = 'frame1';
        $frameUuid2 = 'frame2';
        $frameUuid3 = 'frame3';

        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [$frameUuid1, $frameUuid2]
        );

        $activity2 = $this->createActivity(456, 200, 'activity-456');
        $timesheet2 = TimesheetFactory::create(
            $activity2,
            'Description 2',
            null,
            2.0,
            $date,
            new Role(2, null, 'Manager', 'Manager', 'employee', 'active'),
            false,
            [$frameUuid3]
        );

        $activity3 = $this->createActivity(789, 300, 'activity-789');
        $timesheet3 = TimesheetFactory::create(
            $activity3,
            'Description 3',
            null,
            3.0,
            $date,
            new Role(3, null, 'Lead', 'Lead', 'employee', 'active'),
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet1->uuid => $timesheet1->toArray(),
                $timesheet2->uuid => $timesheet2->toArray(),
                $timesheet3->uuid => $timesheet3->toArray(),
            ]);

        $result = $this->repository->getByFrameUuids([$frameUuid1, $frameUuid3]);

        $this->assertCount(2, $result);
        $resultUuids = array_map(fn($t) => $t->uuid, $result);
        $this->assertContains($timesheet1->uuid, $resultUuids);
        $this->assertContains($timesheet2->uuid, $resultUuids);
        $this->assertNotContains($timesheet3->uuid, $resultUuids);
    }

    public function testUpdate(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Original description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $updatedTimesheet = TimesheetFactory::create(
            $this->activity,
            'Updated description',
            'Client description',
            2.0,
            $date,
            $this->role,
            false,
            [],
            null,
            null,
            Uuid::fromHex($timesheet->uuid)
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet->uuid => $timesheet->toArray(),
            ]);

        $this->storage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($updatedTimesheet) {
                return isset($data[$updatedTimesheet->uuid])
                    && $data[$updatedTimesheet->uuid]['description'] === 'Updated description';
            }));

        $this->repository->update($updatedTimesheet);
    }

    public function testUpdateWithNonExistentUuidThrowsException(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Cannot update timesheet: timesheet with UUID '{$timesheet->uuid}' does not exist."
        );

        $this->repository->update($timesheet);
    }

    public function testRemove(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Description',
            null,
            1.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([
                $timesheet->uuid => $timesheet->toArray(),
            ]);

        $this->storage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($timesheet) {
                return !isset($data[$timesheet->uuid]);
            }));

        $this->repository->remove($timesheet->uuid);
    }

    public function testRemoveWithNonExistentUuidThrowsException(): void
    {
        $this->storage
            ->expects($this->once())
            ->method('read')
            ->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot remove timesheet: timesheet with UUID 'nonexistent' does not exist.");

        $this->repository->remove('nonexistent');
    }
}
