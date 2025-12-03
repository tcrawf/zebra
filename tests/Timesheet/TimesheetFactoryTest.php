<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Exception\TrackException;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\Timesheet;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetFactoryTest extends TestCase
{
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->activity = new Activity(
            EntityKey::zebra(123),
            'Test Activity',
            'Activity Description',
            EntityKey::zebra(100),
            'activity-123'
        );
        $this->role = new Role(5, null, 'Manager', 'Manager', 'employee', 'active');
    }

    public function testCreate(): void
    {
        $date = Carbon::now()->startOfDay();
        $frameUuids = ['abc12345', 'def67890'];
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            'Client description',
            2.5,
            $date,
            $this->role,
            false,
            $frameUuids
        );

        $this->assertInstanceOf(Timesheet::class, $timesheet);
        $this->assertEquals(100, $timesheet->getProjectId());
        $this->assertEquals($this->activity, $timesheet->activity);
        $this->assertEquals('Test description', $timesheet->description);
        $this->assertEquals('Client description', $timesheet->clientDescription);
        $this->assertEquals(2.5, $timesheet->time);
        $this->assertEquals($this->role, $timesheet->role);
        $this->assertEquals(5, $timesheet->role->id);
        $this->assertFalse($timesheet->individualAction);
        $this->assertEquals($frameUuids, $timesheet->frameUuids);
    }

    public function testCreateWithUuid(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            null,
            true,
            [],
            null,
            null,
            $uuid
        );

        $this->assertEquals($uuid->getHex(), $timesheet->uuid);
    }

    public function testCreateGeneratesUuidIfNotProvided(): void
    {
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            null,
            true,
            []
        );

        $this->assertNotEmpty($timesheet->uuid);
        $this->assertEquals(8, strlen($timesheet->uuid));
    }

    public function testFromArray(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15);
        $updatedAt = Carbon::now()->subHour();
        $frameUuids = ['abc12345', 'def67890'];

        $data = [
            'uuid' => $uuid->getHex(),
            'projectId' => 100,
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
                'alias' => 'activity-123',
            ],
            'description' => 'Test description',
            'clientDescription' => 'Client description',
            'time' => 2.5,
            'date' => '2024-01-15',
            'role' => [
                'id' => 5,
                'parentId' => null,
                'name' => 'Manager',
                'fullName' => 'Manager',
                'type' => 'employee',
                'status' => 'active',
            ],
            'individualAction' => false,
            'frameUuids' => $frameUuids,
            'zebraId' => 42,
            'updatedAt' => $updatedAt->timestamp,
        ];

        $timesheet = TimesheetFactory::fromArray($data);

        $this->assertEquals($uuid->getHex(), $timesheet->uuid);
        $this->assertEquals(100, $timesheet->getProjectId());
        $this->assertEquals('Test Activity', $timesheet->activity->name);
        $this->assertEquals('Test description', $timesheet->description);
        $this->assertEquals('Client description', $timesheet->clientDescription);
        $this->assertEquals(2.5, $timesheet->time);
        $this->assertEquals('2024-01-15', $timesheet->date->format('Y-m-d'));
        $this->assertNotNull($timesheet->role);
        $this->assertEquals(5, $timesheet->role->id);
        $this->assertEquals('Manager', $timesheet->role->name);
        $this->assertFalse($timesheet->individualAction);
        $this->assertEquals($frameUuids, $timesheet->frameUuids);
        $this->assertEquals(42, $timesheet->zebraId);
        $this->assertEquals($updatedAt->timestamp, $timesheet->getUpdatedAtTimestamp());
    }

    public function testFromArrayWithOptionalFields(): void
    {
        $uuid = Uuid::random();
        $data = [
            'uuid' => $uuid->getHex(),
            'projectId' => 100,
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
                'alias' => 'activity-123',
            ],
            'description' => 'Test description',
            'time' => 1.0,
            'date' => '2024-01-15',
            'frameUuids' => [],
            'individualAction' => true,
        ];

        $timesheet = TimesheetFactory::fromArray($data);

        $this->assertNull($timesheet->clientDescription);
        $this->assertNull($timesheet->role);
        $this->assertTrue($timesheet->individualAction);
        $this->assertNull($timesheet->zebraId);
    }

    public function testFromArrayMissingUuidThrowsException(): void
    {
        $data = [
            'projectId' => 100,
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
            ],
            'description' => 'Test description',
            'time' => 1.0,
            'date' => '2024-01-15',
            'frameUuids' => [],
        ];

        $this->expectException(TrackException::class);
        $this->expectExceptionMessage("Invalid array format: 'uuid' key is required");

        TimesheetFactory::fromArray($data);
    }

    public function testFromArrayMissingRequiredFieldsThrowsException(): void
    {
        $uuid = Uuid::random();
        $requiredFields = ['activity', 'description', 'time', 'date', 'frameUuids'];

        foreach ($requiredFields as $field) {
            $data = [
                'uuid' => $uuid->getHex(),
                'activity' => [
                    'key' => [
                        'source' => 'zebra',
                        'id' => '123',
                    ],
                    'name' => 'Test Activity',
                    'desc' => 'Activity Description',
                    'project' => [
                        'source' => 'zebra',
                        'id' => '100',
                    ],
                ],
                'description' => 'Test description',
                'time' => 1.0,
                'date' => '2024-01-15',
                'frameUuids' => [],
                'individualAction' => true,
            ];
            unset($data[$field]);

            $this->expectException(TrackException::class);
            $this->expectExceptionMessage("Invalid array format: '{$field}' key is required");

            TimesheetFactory::fromArray($data);
        }
    }

    public function testFromArrayInvalidFrameUuidsThrowsException(): void
    {
        $uuid = Uuid::random();
        $data = [
            'uuid' => $uuid->getHex(),
            'projectId' => 100,
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
            ],
            'description' => 'Test description',
            'time' => 1.0,
            'date' => '2024-01-15',
            'frameUuids' => 'not-an-array',
        ];

        $this->expectException(TrackException::class);
        $this->expectExceptionMessage("Invalid array format: 'frameUuids' must be an array");

        TimesheetFactory::fromArray($data);
    }

    public function testCreateWithDoNotSyncDefaultFalse(): void
    {
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            null,
            true,
            []
        );

        $this->assertFalse($timesheet->doNotSync);
    }

    public function testCreateWithDoNotSyncTrue(): void
    {
        $date = Carbon::now()->startOfDay();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            1.0,
            $date,
            null,
            true,
            [],
            null,
            null,
            null,
            true
        );

        $this->assertTrue($timesheet->doNotSync);
    }

    public function testFromArrayWithDoNotSyncTrue(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15);
        $data = [
            'uuid' => $uuid->getHex(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
            ],
            'description' => 'Test description',
            'time' => 1.0,
            'date' => '2024-01-15',
            'frameUuids' => [],
            'individualAction' => true,
            'doNotSync' => true,
        ];

        $timesheet = TimesheetFactory::fromArray($data);

        $this->assertTrue($timesheet->doNotSync);
    }

    public function testFromArrayWithDoNotSyncFalse(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15);
        $data = [
            'uuid' => $uuid->getHex(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
            ],
            'description' => 'Test description',
            'time' => 1.0,
            'date' => '2024-01-15',
            'frameUuids' => [],
            'individualAction' => true,
            'doNotSync' => false,
        ];

        $timesheet = TimesheetFactory::fromArray($data);

        $this->assertFalse($timesheet->doNotSync);
    }

    public function testFromArrayWithDoNotSyncMissingDefaultsToFalse(): void
    {
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15);
        $data = [
            'uuid' => $uuid->getHex(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
            ],
            'description' => 'Test description',
            'time' => 1.0,
            'date' => '2024-01-15',
            'frameUuids' => [],
            'individualAction' => true,
            // Note: doNotSync is intentionally missing to test backwards compatibility
        ];

        $timesheet = TimesheetFactory::fromArray($data);

        $this->assertFalse($timesheet->doNotSync);
    }

    public function testBackwardsCompatibilityWithOldTimesheetData(): void
    {
        // Simulate old timesheet data without doNotSync field
        $uuid = Uuid::random();
        $date = Carbon::create(2024, 1, 15);
        $updatedAt = Carbon::now()->subHour();
        $oldData = [
            'uuid' => $uuid->getHex(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Activity Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
            ],
            'description' => 'Test description',
            'time' => 1.0,
            'date' => '2024-01-15',
            'frameUuids' => [],
            'individualAction' => true,
            'zebraId' => 42,
            'updatedAt' => $updatedAt->timestamp,
            // Note: doNotSync is intentionally missing to test backwards compatibility
        ];

        // Should load successfully with doNotSync defaulting to false
        $timesheet = TimesheetFactory::fromArray($oldData);

        $this->assertFalse($timesheet->doNotSync);
        $this->assertEquals($uuid->getHex(), $timesheet->uuid);
        $this->assertEquals('Test description', $timesheet->description);

        // When serialized, should include doNotSync field
        $serialized = $timesheet->toArray();
        $this->assertArrayHasKey('doNotSync', $serialized);
        $this->assertFalse($serialized['doNotSync']);

        // Should be able to round-trip through serialization
        $reloaded = TimesheetFactory::fromArray($serialized);
        $this->assertFalse($reloaded->doNotSync);
    }

    public function testFromApiResponse(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12345,
            'occupation_id' => 123,
            'date' => '2024-01-15',
            'time' => 2.5,
            'description' => 'API description',
            'lu_date' => '2024-01-15 10:00:00',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $userRepo
            ->expects($this->never())
            ->method('getCurrentUserRoles');

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertInstanceOf(Timesheet::class, $timesheet);
        $this->assertEquals(12345, $timesheet->zebraId);
        $this->assertEquals('API description', $timesheet->description);
        $this->assertEquals(2.5, $timesheet->time);
        $this->assertEquals('2024-01-15', $timesheet->date->format('Y-m-d'));
        $this->assertEquals($this->activity, $timesheet->activity);
    }

    public function testFromApiResponseWithOccupid(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12346,
            'occupid' => 123, // Alternative field name
            'date' => '2024-01-16',
            'time' => 1.25,
            'description' => 'API description with occupid',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertEquals(12346, $timesheet->zebraId);
        $this->assertEquals($this->activity, $timesheet->activity);
    }

    public function testFromApiResponseWithRole(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);
        $userRole = new Role(5, null, 'Manager', 'Manager', 'employee', 'active');

        $apiData = [
            'id' => 12347,
            'occupation_id' => 123,
            'date' => '2024-01-17',
            'time' => 3.0,
            'description' => 'API description with role',
            'role_id' => 5,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $userRepo
            ->expects($this->once())
            ->method('getCurrentUserRoles')
            ->willReturn([$userRole]);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertNotNull($timesheet->role);
        $this->assertEquals(5, $timesheet->role->id);
        $this->assertEquals('Manager', $timesheet->role->name);
    }

    public function testFromApiResponseWithRoleNotFound(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);
        $userRole = new Role(10, null, 'Other', 'Other', 'employee', 'active');

        $apiData = [
            'id' => 12348,
            'occupation_id' => 123,
            'date' => '2024-01-18',
            'time' => 1.0,
            'description' => 'API description with unknown role',
            'role_id' => 99, // Not in user roles
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $userRepo
            ->expects($this->once())
            ->method('getCurrentUserRoles')
            ->willReturn([$userRole]);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        // Should create minimal Role object
        $this->assertNotNull($timesheet->role);
        $this->assertEquals(99, $timesheet->role->id);
    }

    public function testFromApiResponseWithClientDescription(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12349,
            'occupation_id' => 123,
            'date' => '2024-01-19',
            'time' => 2.0,
            'description' => 'API description',
            'client_description' => 'Client description',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertEquals('Client description', $timesheet->clientDescription);
    }

    public function testFromApiResponseWithIndividualAction(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12350,
            'occupation_id' => 123,
            'date' => '2024-01-20',
            'time' => 1.5,
            'description' => 'API description',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertTrue($timesheet->individualAction);
    }

    public function testFromApiResponseWithModifiedField(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12351,
            'occupation_id' => 123,
            'date' => '2024-01-21',
            'time' => 1.0,
            'description' => 'API description',
            'modified' => '2024-01-21 15:30:00',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertNotNull($timesheet->getUpdatedAtTimestamp());
    }

    public function testFromApiResponseThrowsExceptionWhenOccupationIdMissing(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12352,
            'date' => '2024-01-22',
            'time' => 1.0,
            'description' => 'API description',
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid API data: 'occupation_id' or 'occupid' is required");

        TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);
    }

    public function testFromApiResponseThrowsExceptionWhenDateMissing(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12353,
            'occupation_id' => 123,
            'time' => 1.0,
            'description' => 'API description',
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid API data: 'date' is required and must be a string");

        TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);
    }

    public function testFromApiResponseThrowsExceptionWhenTimeMissing(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12354,
            'occupation_id' => 123,
            'date' => '2024-01-23',
            'description' => 'API description',
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid API data: 'time' is required and must be numeric");

        TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);
    }

    public function testFromApiResponseThrowsExceptionWhenDescriptionMissing(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12355,
            'occupation_id' => 123,
            'date' => '2024-01-24',
            'time' => 1.0,
        ];

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage("Invalid API data: 'description' is required and must be a string");

        TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);
    }

    public function testFromApiResponseThrowsExceptionWhenActivityNotFound(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12356,
            'occupation_id' => 999,
            'date' => '2024-01-25',
            'time' => 1.0,
            'description' => 'API description',
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $this->expectException(\Tcrawf\Zebra\Exception\TrackException::class);
        $this->expectExceptionMessage('Activity not found for occupation_id: 999');

        TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);
    }

    public function testFromApiResponseWithStringOccupationId(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12357,
            'occupation_id' => '123', // String instead of int
            'date' => '2024-01-26',
            'time' => 1.0,
            'description' => 'API description',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertInstanceOf(Timesheet::class, $timesheet);
    }

    public function testFromApiResponseWithStringRoleId(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);
        $userRole = new Role(5, null, 'Manager', 'Manager', 'employee', 'active');

        $apiData = [
            'id' => 12358,
            'occupation_id' => 123,
            'date' => '2024-01-27',
            'time' => 1.0,
            'description' => 'API description',
            'role_id' => '5', // String instead of int
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $userRepo
            ->expects($this->once())
            ->method('getCurrentUserRoles')
            ->willReturn([$userRole]);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertNotNull($timesheet->role);
        $this->assertEquals(5, $timesheet->role->id);
    }

    public function testFromApiResponseGeneratesUuidIfNotProvided(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12359,
            'occupation_id' => 123,
            'date' => '2024-01-28',
            'time' => 1.0,
            'description' => 'API description',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertNotEmpty($timesheet->uuid);
        $this->assertEquals(8, strlen($timesheet->uuid));
    }

    public function testFromApiResponseWithProvidedUuid(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);
        $uuid = Uuid::random();

        $apiData = [
            'id' => 12360,
            'occupation_id' => 123,
            'date' => '2024-01-29',
            'time' => 1.0,
            'description' => 'API description',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo, $uuid);

        $this->assertEquals($uuid->getHex(), $timesheet->uuid);
    }

    public function testFromApiResponseWithEmptyFrameUuids(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12361,
            'occupation_id' => 123,
            'date' => '2024-01-30',
            'time' => 1.0,
            'description' => 'API description',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        // Frame UUIDs should be empty for API responses
        $this->assertEmpty($timesheet->frameUuids);
    }

    public function testFromApiResponseWithInvalidLuDate(): void
    {
        $activityRepo = $this->createMock(\Tcrawf\Zebra\Activity\ActivityRepositoryInterface::class);
        $userRepo = $this->createMock(\Tcrawf\Zebra\User\UserRepositoryInterface::class);

        $apiData = [
            'id' => 12362,
            'occupation_id' => 123,
            'date' => '2024-01-31',
            'time' => 1.0,
            'description' => 'API description',
            'lu_date' => 'invalid-date',
            'individual_action' => true,
        ];

        $activityRepo
            ->expects($this->once())
            ->method('get')
            ->willReturn($this->activity);

        // Should not throw exception, but use current time as fallback
        $timesheet = TimesheetFactory::fromApiResponse($apiData, $activityRepo, $userRepo);

        $this->assertInstanceOf(Timesheet::class, $timesheet);
        $this->assertNotNull($timesheet->getUpdatedAtTimestamp());
    }
}
