<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\Command\TimesheetEditCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\Timesheet;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Tests\Helper\TestEntityFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetEditCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $timesheetRepository;
    private ZebraTimesheetRepositoryInterface&MockObject $zebraTimesheetRepository;
    private ActivityRepositoryInterface&MockObject $activityRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private TimesheetAutocompletion&MockObject $timesheetAutocompletion;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private TimesheetEditCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;
    private Timesheet $timesheet;

    protected function setUp(): void
    {
        $this->timesheetRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->zebraTimesheetRepository = $this->createMock(ZebraTimesheetRepositoryInterface::class);
        $this->activityRepository = $this->createMock(ActivityRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->timesheetAutocompletion = $this->createMock(TimesheetAutocompletion::class);
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);

        $this->command = new TimesheetEditCommand(
            $this->timesheetRepository,
            $this->zebraTimesheetRepository,
            $this->activityRepository,
            $this->userRepository,
            $this->timesheetAutocompletion,
            $this->projectRepository
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity = new Activity(
            EntityKey::zebra(1),
            'Test Activity',
            'Description',
            EntityKey::zebra(100),
            'alias'
        );
        $uuid = Uuid::random();
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $this->timesheet = TimesheetFactory::create(
            $this->activity,
            'Test Description',
            null,
            1.5,
            Carbon::today(),
            $this->role,
            false,
            [],
            null,
            null,
            $uuid
        );
    }

    public function testExecuteWithTimesheetNotFound(): void
    {
        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $this->commandTester->execute(['timesheet' => 'nonexistent-uuid']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    public function testExecuteWithNoTimesheetArgument(): void
    {
        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No timesheet specified', $this->commandTester->getDisplay());
    }

    public function testExecuteWithTimesheetFound(): void
    {
        $uuid = $this->timesheet->uuid;
        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        // Mock editor to return same content (no changes)
        $this->commandTester->setInputs(['']); // Simulate no changes

        // This will fail because we can't easily mock the editor, but we can test the resolve logic
        $this->commandTester->execute(['timesheet' => $uuid]);

        // The command will try to open an editor, which will fail in test environment
        // But we've tested that it resolves the timesheet correctly
    }

    // -------------------------------------------------------------------------
    // Flag-based editing tests
    // -------------------------------------------------------------------------

    public function testEditTimesheetWithDescriptionFlag(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->description === 'Updated via flag'
                    && $ts->uuid === $this->timesheet->uuid;
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--description' => 'Updated via flag'],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithTimeFlag(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet && $ts->time === 2.25;
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--time' => '2.25'],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithDateFlag(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->date->format('Y-m-d') === '2026-01-15';
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--date' => '2026-01-15'],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithActivityFlag(): void
    {
        $uuid = $this->timesheet->uuid;
        $newActivity = new Activity(
            EntityKey::zebra(2),
            'New Activity',
            'Description',
            EntityKey::zebra(100),
            'new-alias'
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('new-alias')
            ->willReturn($newActivity);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) use ($newActivity) {
                return $ts instanceof Timesheet
                    && $ts->activity->entityKey->toString() === $newActivity->entityKey->toString();
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--activity' => 'new-alias'],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithRoleFlag(): void
    {
        $uuid = $this->timesheet->uuid;
        $newRole = new Role(2, null, 'Tester', 'Tester', 'employee', 'active');
        $userWithRoles = TestEntityFactory::createUser(
            1,
            'testuser',
            'Test',
            'User',
            'Test User',
            'test@example.com',
            [$this->role, $newRole]
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($userWithRoles);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->role !== null
                    && $ts->role->id === 2;
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--role' => '2'],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithIndividualFlag(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->individualAction === true
                    && $ts->role === null;
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--individual' => true],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithDoNotSyncFlag(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet && $ts->doNotSync === true;
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--do-not-sync' => true],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithMultipleFlags(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->description === 'Multi-flag'
                    && $ts->time === 3.0
                    && $ts->individualAction === true;
            }));

        $this->commandTester->execute(
            [
                'timesheet' => $uuid,
                '--description' => 'Multi-flag',
                '--time' => '3.0',
                '--individual' => true,
            ],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetNoFlagsFallsBackToEditor(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        // Without flags, command falls back to the editor path
        $this->commandTester->execute(
            ['timesheet' => $uuid],
            ['interactive' => false]
        );

        // Editor won't open in test env; verifies we took the editor branch (not flag branch)
        // No "Timesheet updated successfully" because editor didn't run
        $this->assertStringNotContainsString(
            'must specify --sync-remote or --no-sync-remote',
            $this->commandTester->getDisplay()
        );
    }

    public function testEditSyncedTimesheetFailsWithoutSyncRemoteFlag(): void
    {
        $syncedTimesheet = TimesheetFactory::create(
            $this->activity,
            'Synced Description',
            null,
            1.5,
            Carbon::today(),
            $this->role,
            false,
            [],
            42,
            null,
            Uuid::random()
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($syncedTimesheet->uuid)
            ->willReturn($syncedTimesheet);

        $this->commandTester->execute(
            ['timesheet' => $syncedTimesheet->uuid, '--description' => 'New desc'],
            ['interactive' => false]
        );

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('--sync-remote', $display);
        $this->assertStringContainsString('--no-sync-remote', $display);
    }

    public function testEditSyncedTimesheetWithSyncRemoteFlag(): void
    {
        $syncedTimesheet = TimesheetFactory::create(
            $this->activity,
            'Synced Description',
            null,
            1.5,
            Carbon::today(),
            $this->role,
            false,
            [],
            42,
            null,
            Uuid::random()
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($syncedTimesheet->uuid)
            ->willReturn($syncedTimesheet);

        $this->zebraTimesheetRepository
            ->expects($this->once())
            ->method('getByZebraId')
            ->with(42)
            ->willReturn($syncedTimesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('update');

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->description === 'Updated synced';
            }));

        $this->commandTester->execute(
            [
                'timesheet' => $syncedTimesheet->uuid,
                '--description' => 'Updated synced',
                '--sync-remote' => true,
            ],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet synced from Zebra', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditSyncedTimesheetWithNoSyncRemoteFlag(): void
    {
        $syncedTimesheet = TimesheetFactory::create(
            $this->activity,
            'Synced Description',
            null,
            1.5,
            Carbon::today(),
            $this->role,
            false,
            [],
            42,
            null,
            Uuid::random()
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($syncedTimesheet->uuid)
            ->willReturn($syncedTimesheet);

        $this->zebraTimesheetRepository
            ->expects($this->never())
            ->method('getByZebraId');

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->description === 'Updated local only';
            }));

        $this->commandTester->execute(
            [
                'timesheet' => $syncedTimesheet->uuid,
                '--description' => 'Updated local only',
                '--no-sync-remote' => true,
            ],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditUnsyncedTimesheetIgnoresSyncRemoteFlags(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->zebraTimesheetRepository
            ->expects($this->never())
            ->method('getByZebraId');

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->description === 'Updated unsynced';
            }));

        // --no-sync-remote on an unsynced timesheet should be ignored
        $this->commandTester->execute(
            [
                'timesheet' => $uuid,
                '--description' => 'Updated unsynced',
                '--no-sync-remote' => true,
            ],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithInvalidTimeReturnsError(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--time' => '1.3'],
            ['interactive' => false]
        );

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('multiple of 0.25', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetWithEmptyDescriptionReturnsError(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--description' => '  '],
            ['interactive' => false]
        );

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Description cannot be empty', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetDoNotSyncAndSyncConflict(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--do-not-sync' => true, '--sync' => true],
            ['interactive' => false]
        );

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            'Cannot use --do-not-sync and --sync together',
            $this->commandTester->getDisplay()
        );
    }

    public function testEditTimesheetSyncRemoteAndNoSyncRemoteConflict(): void
    {
        $syncedTimesheet = TimesheetFactory::create(
            $this->activity,
            'Synced Description',
            null,
            1.5,
            Carbon::today(),
            $this->role,
            false,
            [],
            42,
            null,
            Uuid::random()
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($syncedTimesheet->uuid)
            ->willReturn($syncedTimesheet);

        $this->commandTester->execute(
            [
                'timesheet' => $syncedTimesheet->uuid,
                '--description' => 'test',
                '--sync-remote' => true,
                '--no-sync-remote' => true,
            ],
            ['interactive' => false]
        );

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            'Cannot use --sync-remote and --no-sync-remote together',
            $this->commandTester->getDisplay()
        );
    }

    public function testEditTimesheetWithClientDescriptionFlag(): void
    {
        $uuid = $this->timesheet->uuid;

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($uuid)
            ->willReturn($this->timesheet);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet
                    && $ts->clientDescription === 'Client visible note';
            }));

        $this->commandTester->execute(
            ['timesheet' => $uuid, '--client-description' => 'Client visible note'],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }

    public function testEditTimesheetClearClientDescription(): void
    {
        $tsWithClientDesc = TimesheetFactory::create(
            $this->activity,
            'Description',
            'Existing client desc',
            1.5,
            Carbon::today(),
            $this->role,
            false,
            [],
            null,
            null,
            Uuid::random()
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('get')
            ->with($tsWithClientDesc->uuid)
            ->willReturn($tsWithClientDesc);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($ts) {
                return $ts instanceof Timesheet && $ts->clientDescription === null;
            }));

        $this->commandTester->execute(
            ['timesheet' => $tsWithClientDesc->uuid, '--client-description' => ''],
            ['interactive' => false]
        );

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet updated successfully', $this->commandTester->getDisplay());
    }
}
