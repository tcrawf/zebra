<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\TimesheetApiServiceInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepository;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class ZebraTimesheetRepositoryTest extends TestCase
{
    private TimesheetApiServiceInterface&MockObject $apiService;
    private ActivityRepositoryInterface&MockObject $activityRepo;
    private UserRepositoryInterface&MockObject $userRepo;
    private ZebraTimesheetRepository $repository;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->apiService = $this->createMock(TimesheetApiServiceInterface::class);
        $this->activityRepo = $this->createMock(ActivityRepositoryInterface::class);
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);
        $this->repository = new ZebraTimesheetRepository(
            $this->apiService,
            $this->activityRepo,
            $this->userRepo
        );

        $this->activity = new Activity(
            EntityKey::zebra(123),
            'Test Activity',
            'Description',
            EntityKey::zebra(100)
        );
        $this->role = new Role(1, null, 'Developer');
    }

    public function testAll(): void
    {
        $apiData = [
            12345 => [
                'id' => 12345,
                'occupation_id' => 123,
                'date' => '2024-01-15',
                'time' => 2.5,
                'description' => 'Test description',
                'individual_action' => true,
            ],
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($apiData);

        $this->activityRepo
            ->method('get')
            ->willReturn($this->activity);

        $result = $this->repository->all();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testGetReturnsNull(): void
    {
        // UUIDs are local-only, so get() always returns null
        $result = $this->repository->get('some-uuid');
        $this->assertNull($result);
    }

    public function testGetByZebraId(): void
    {
        $zebraId = 12345;
        $apiData = [
            'id' => $zebraId,
            'occupation_id' => 123,
            'date' => '2024-01-15',
            'time' => 2.5,
            'description' => 'Test description',
            'individual_action' => true,
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($zebraId)
            ->willReturn($apiData);

        $this->activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $result = $this->repository->getByZebraId($zebraId);

        $this->assertNotNull($result);
        $this->assertEquals($zebraId, $result->zebraId);
    }

    public function testGetByZebraIdReturnsNullOn404(): void
    {
        $zebraId = 99999;
        $exception = new ZebraApiException('Timesheet not found', 404);

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($zebraId)
            ->willThrowException($exception);

        $result = $this->repository->getByZebraId($zebraId);

        $this->assertNull($result);
    }

    public function testGetByZebraIdThrowsExceptionOnOtherErrors(): void
    {
        $zebraId = 12345;
        $exception = new ZebraApiException('Server error', 500);

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($zebraId)
            ->willThrowException($exception);

        $this->expectException(ZebraApiException::class);

        $this->repository->getByZebraId($zebraId);
    }

    public function testGetByDateRange(): void
    {
        $from = Carbon::create(2024, 1, 15);
        $to = Carbon::create(2024, 1, 20);
        $apiData = [
            12345 => [
                'id' => 12345,
                'occupation_id' => 123,
                'date' => '2024-01-16',
                'time' => 2.5,
                'description' => 'Test description',
                'individual_action' => true,
            ],
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->callback(function ($filters) {
                return isset($filters['start_date']) && isset($filters['end_date']);
            }))
            ->willReturn($apiData);

        $this->activityRepo
            ->method('get')
            ->willReturn($this->activity);

        $result = $this->repository->getByDateRange($from, $to);

        $this->assertIsArray($result);
    }

    public function testGetByDateRangeWithOnlyFrom(): void
    {
        $from = Carbon::create(2024, 1, 15);
        $apiData = [];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->callback(function ($filters) {
                return isset($filters['start_date']) && !isset($filters['end_date']);
            }))
            ->willReturn($apiData);

        $result = $this->repository->getByDateRange($from);

        $this->assertIsArray($result);
    }

    public function testGetByFrameUuidsReturnsEmptyArray(): void
    {
        // Frame UUIDs are local-only and not stored in Zebra API
        $result = $this->repository->getByFrameUuids(['frame1', 'frame2']);
        $this->assertEquals([], $result);
    }

    public function testCreate(): void
    {
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            Carbon::create(2024, 1, 15),
            $this->role,
            false,
            []
        );

        $response = [
            'success' => true,
            'data' => [
                'timesheet' => [
                    'id' => 12345,
                    'occupation_id' => 123,
                    'date' => '2024-01-15',
                    'time' => 2.5,
                    'description' => 'Test description',
                    'role_id' => 1,
                ],
            ],
        ];

        $this->apiService
            ->expects($this->once())
            ->method('create')
            ->willReturn($response);

        $this->activityRepo
            ->method('get')
            ->willReturn($this->activity);

        $this->userRepo
            ->expects($this->once())
            ->method('getCurrentUserRoles')
            ->willReturn([$this->role]);

        $result = $this->repository->create($timesheet);

        $this->assertNotNull($result);
        $this->assertEquals(12345, $result->zebraId);
    }

    public function testCreateWithIdInData(): void
    {
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            Carbon::create(2024, 1, 15),
            $this->role,
            false,
            []
        );

        $response = [
            'success' => true,
            'data' => [
                'id' => 12345,
            ],
        ];

        $apiData = [
            'id' => 12345,
            'occupation_id' => 123,
            'date' => '2024-01-15',
            'time' => 2.5,
            'description' => 'Test description',
            'individual_action' => true,
        ];

        $this->apiService
            ->expects($this->once())
            ->method('create')
            ->willReturn($response);

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with(12345)
            ->willReturn($apiData);

        $this->activityRepo
            ->method('get')
            ->willReturn($this->activity);

        $result = $this->repository->create($timesheet);

        $this->assertNotNull($result);
        $this->assertEquals(12345, $result->zebraId);
    }

    public function testUpdate(): void
    {
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            Carbon::create(2024, 1, 15),
            $this->role,
            false,
            [],
            12345
        );

        $apiData = [
            'id' => 12345,
            'occupation_id' => 123,
            'date' => '2024-01-15',
            'time' => 2.5,
            'description' => 'Updated description',
            'individual_action' => true,
        ];

        $this->apiService
            ->expects($this->once())
            ->method('update')
            ->with(12345, $this->isType('array'));

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with(12345)
            ->willReturn($apiData);

        $this->activityRepo
            ->method('get')
            ->willReturn($this->activity);

        $confirmed = true;
        $result = $this->repository->update($timesheet, fn($t) => $confirmed);

        $this->assertNotNull($result);
    }

    public function testUpdateReturnsNullWhenCancelled(): void
    {
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            Carbon::create(2024, 1, 15),
            $this->role,
            false,
            [],
            12345
        );

        $confirmed = false;
        $result = $this->repository->update($timesheet, fn($t) => $confirmed);

        $this->assertNull($result);
    }

    public function testUpdateThrowsExceptionWhenZebraIdMissing(): void
    {
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            Carbon::create(2024, 1, 15),
            $this->role,
            false,
            []
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot update timesheet without zebraId');

        $this->repository->update($timesheet, fn($t) => true);
    }

    public function testDelete(): void
    {
        $zebraId = 12345;

        $this->apiService
            ->expects($this->once())
            ->method('delete')
            ->with($zebraId);

        $confirmed = true;
        $result = $this->repository->delete($zebraId, fn($id) => $confirmed);

        $this->assertTrue($result);
    }

    public function testDeleteReturnsFalseWhenCancelled(): void
    {
        $zebraId = 12345;

        $confirmed = false;
        $result = $this->repository->delete($zebraId, fn($id) => $confirmed);

        $this->assertFalse($result);
    }

    public function testFetchRawApiData(): void
    {
        $zebraId = 12345;
        $apiData = [
            'id' => $zebraId,
            'occupation_id' => 123,
            'date' => '2024-01-15',
            'time' => 2.5,
            'description' => 'Test description',
            'individual_action' => true,
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($zebraId)
            ->willReturn($apiData);

        $result = $this->repository->fetchRawApiData($zebraId);

        $this->assertEquals($apiData, $result);
    }

    public function testConvertApiDataToTimesheetsSkipsInvalidData(): void
    {
        $apiData = [
            12345 => [
                'id' => 12345,
                'occupation_id' => 123,
                'date' => '2024-01-15',
                'time' => 2.5,
                'description' => 'Test description',
                'individual_action' => true,
            ],
            12346 => [
                'id' => 12346,
                // Missing required fields
            ],
        ];

        $this->apiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($apiData);

        $this->activityRepo
            ->method('get')
            ->willReturnCallback(function ($key) {
                if ($key->toString() === EntityKey::zebra(123)->toString()) {
                    return $this->activity;
                }
                return null;
            });

        $result = $this->repository->all();

        // Should only include valid timesheet
        $this->assertCount(1, $result);
    }
}
