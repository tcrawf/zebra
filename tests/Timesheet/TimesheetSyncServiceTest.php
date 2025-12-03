<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\TimesheetInterface;
use Tcrawf\Zebra\Timesheet\TimesheetSyncService;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;

class TimesheetSyncServiceTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $localRepository;
    private ZebraTimesheetRepositoryInterface&MockObject $zebraRepository;
    private TimesheetSyncService $syncService;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->localRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->zebraRepository = $this->createMock(ZebraTimesheetRepositoryInterface::class);
        $this->syncService = new TimesheetSyncService($this->localRepository, $this->zebraRepository);

        $this->activity = new Activity(
            EntityKey::zebra(123),
            'Test Activity',
            'Activity Description',
            EntityKey::zebra(100),
            'activity-123'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    public function testPushLocalToZebraCreatesNewTimesheet(): void
    {
        $date = Carbon::now()->setTimezone('Europe/Zurich')->startOfDay();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            ['frame-uuid-1'],
            null // No zebraId
        );

        $remoteTimesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345, // zebraId from API
            Carbon::now()->addHour()
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('create')
            ->with($localTimesheet)
            ->willReturn($remoteTimesheet);

        $this->localRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TimesheetInterface $timesheet) use ($localTimesheet) {
                return $timesheet->zebraId === 12345
                    && $timesheet->uuid === $localTimesheet->uuid
                    && $timesheet->frameUuids === ['frame-uuid-1'];
            }));

        $result = $this->syncService->pushLocalToZebra($localTimesheet);

        $this->assertNotNull($result->zebraId);
        $this->assertEquals(12345, $result->zebraId);
    }

    public function testPushLocalToZebraUpdatesExistingTimesheet(): void
    {
        $date = Carbon::now()->setTimezone('Europe/Zurich')->startOfDay();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Updated description',
            null,
            3.0,
            $date,
            $this->role,
            false,
            ['frame-uuid-1'],
            12345 // Has zebraId
        );

        $remoteTimesheet = TimesheetFactory::create(
            $this->activity,
            'Updated description',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()->addHour()
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('update')
            ->with($localTimesheet, $this->isType('callable'))
            ->willReturn($remoteTimesheet);

        $this->localRepository
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (TimesheetInterface $timesheet) use ($localTimesheet) {
                return $timesheet->zebraId === 12345
                    && $timesheet->uuid === $localTimesheet->uuid
                    && $timesheet->frameUuids === ['frame-uuid-1'];
            }));

        // Provide a confirmation callback that returns true
        $result = $this->syncService->pushLocalToZebra($localTimesheet, fn() => true);

        $this->assertEquals(12345, $result->zebraId);
    }

    public function testPullFromZebraCreatesNewLocalTimesheet(): void
    {
        $date = Carbon::now()->setTimezone('Europe/Zurich')->startOfDay();
        $remoteTimesheet = TimesheetFactory::create(
            $this->activity,
            'Remote description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$remoteTimesheet]);

        $this->localRepository
            ->expects($this->once())
            ->method('getByZebraId')
            ->with(12345)
            ->willReturn(null);

        $this->localRepository
            ->expects($this->once())
            ->method('save')
            ->with($remoteTimesheet);

        $result = $this->syncService->pullFromZebra($date);

        $this->assertCount(1, $result);
        $this->assertEquals(12345, $result[0]->zebraId);
    }

    public function testPullFromZebraUpdatesLocalWhenRemoteIsNewer(): void
    {
        $date = Carbon::now()->setTimezone('Europe/Zurich')->startOfDay();
        $localUpdatedAt = Carbon::now()->subHour();
        $remoteUpdatedAt = Carbon::now();

        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Local description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            ['frame-uuid-1'],
            12345,
            $localUpdatedAt
        );

        $remoteTimesheet = TimesheetFactory::create(
            $this->activity,
            'Remote description',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12345,
            $remoteUpdatedAt
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$remoteTimesheet]);

        $this->localRepository
            ->expects($this->once())
            ->method('getByZebraId')
            ->with(12345)
            ->willReturn($localTimesheet);

        $this->localRepository
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (TimesheetInterface $timesheet) {
                return $timesheet->zebraId === 12345
                    && $timesheet->description === 'Remote description'
                    && $timesheet->time === 3.0
                    && $timesheet->frameUuids === ['frame-uuid-1']; // Preserved
            }));

        $result = $this->syncService->pullFromZebra($date);

        $this->assertCount(1, $result);
    }

    public function testPullFromZebraSkipsWhenLocalIsNewer(): void
    {
        $date = Carbon::now()->setTimezone('Europe/Zurich')->startOfDay();
        $localUpdatedAt = Carbon::now();
        $remoteUpdatedAt = Carbon::now()->subHour();

        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Local description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            ['frame-uuid-1'],
            12345,
            $localUpdatedAt
        );

        $remoteTimesheet = TimesheetFactory::create(
            $this->activity,
            'Remote description',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12345,
            $remoteUpdatedAt
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$remoteTimesheet]);

        $this->localRepository
            ->expects($this->once())
            ->method('getByZebraId')
            ->with(12345)
            ->willReturn($localTimesheet);

        $this->localRepository
            ->expects($this->never())
            ->method('update');

        $result = $this->syncService->pullFromZebra($date);

        $this->assertEmpty($result);
    }
}
