<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\TimesheetPushCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\TimesheetSyncServiceInterface;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;

class TimesheetPushCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $localRepository;
    private ZebraTimesheetRepositoryInterface&MockObject $zebraRepository;
    private TimesheetSyncServiceInterface&MockObject $syncService;
    private TimesheetAutocompletion&MockObject $timesheetAutocompletion;
    private TimesheetPushCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->localRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->zebraRepository = $this->createMock(ZebraTimesheetRepositoryInterface::class);
        $this->syncService = $this->createMock(TimesheetSyncServiceInterface::class);
        $this->timesheetAutocompletion = $this->createMock(TimesheetAutocompletion::class);

        $this->command = new TimesheetPushCommand(
            $this->localRepository,
            $this->zebraRepository,
            $this->syncService,
            $this->timesheetAutocompletion
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity = new Activity(
            EntityKey::zebra(123),
            'Test Activity',
            'Activity Description',
            EntityKey::zebra(100),
            'activity-123'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    public function testPushWithNoTimesheets(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                }),
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                })
            )
            ->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No local timesheets found', $this->commandTester->getDisplay());
    }

    public function testPushNewTimesheetWithConfirmation(): void
    {
        $date = Carbon::today();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null
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
            12345,
            Carbon::now()
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                }),
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                })
            )
            ->willReturn([$localTimesheet]);

        $this->syncService
            ->expects($this->once())
            ->method('pushLocalToZebra')
            ->with($localTimesheet, null)
            ->willReturn($remoteTimesheet);

        $this->commandTester->setInputs(['Confirm each', 'yes']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pushed', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Created timesheet in Zebra', $this->commandTester->getDisplay());
    }

    public function testPushNewTimesheetWithForceFlag(): void
    {
        $date = Carbon::today();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null
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
            12345,
            Carbon::now()
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                }),
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                })
            )
            ->willReturn([$localTimesheet]);

        $this->syncService
            ->expects($this->once())
            ->method('pushLocalToZebra')
            ->with($localTimesheet, null)
            ->willReturn($remoteTimesheet);

        $this->commandTester->execute(['--force' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Created timesheet in Zebra', $this->commandTester->getDisplay());
    }

    public function testPushExistingTimesheetWithRemoteNewer(): void
    {
        $date = Carbon::today();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()->subHour()
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
            Carbon::now()
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                }),
                $this->callback(function ($date) {
                    return $date instanceof Carbon && $date->isToday();
                })
            )
            ->willReturn([$localTimesheet]);

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByZebraId')
            ->with(12345)
            ->willReturn($remoteTimesheet);

        $this->commandTester->setInputs(['Confirm each', 'no']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pushed', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Remote timesheet', $this->commandTester->getDisplay());
        $this->assertStringContainsString('is newer', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Skipped', $this->commandTester->getDisplay());
    }

    public function testPushWithDateOption(): void
    {
        $date = Carbon::parse('2024-01-15')->startOfDay();

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($d) use ($date) {
                    return $d instanceof Carbon && $d->format('Y-m-d') === $date->format('Y-m-d');
                }),
                $this->callback(function ($d) use ($date) {
                    return $d instanceof Carbon && $d->format('Y-m-d') === $date->format('Y-m-d');
                })
            )
            ->willReturn([]);

        $this->commandTester->execute(['--date' => '2024-01-15']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testPushWithYesterdayOption(): void
    {
        $yesterday = Carbon::yesterday();

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($d) use ($yesterday) {
                    return $d instanceof Carbon && $d->format('Y-m-d') === $yesterday->format('Y-m-d');
                }),
                $this->callback(function ($d) use ($yesterday) {
                    return $d instanceof Carbon && $d->format('Y-m-d') === $yesterday->format('Y-m-d');
                })
            )
            ->willReturn([]);

        $this->commandTester->execute(['--yesterday' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testPushWithInvalidDate(): void
    {
        $this->commandTester->execute(['--date' => 'invalid-date']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid date format', $this->commandTester->getDisplay());
    }

    public function testPushWithBothDateAndYesterdayOptions(): void
    {
        $this->commandTester->execute(['--date' => '2024-01-15', '--yesterday' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Cannot specify both', $this->commandTester->getDisplay());
    }

    public function testPushMultipleTimesheetsWithConfirmAll(): void
    {
        $date = Carbon::today();
        $localTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Test description 1',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $localTimesheet2 = TimesheetFactory::create(
            $this->activity,
            'Test description 2',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $remoteTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Test description 1',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()
        );

        $remoteTimesheet2 = TimesheetFactory::create(
            $this->activity,
            'Test description 2',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12346,
            Carbon::now()
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$localTimesheet1, $localTimesheet2]);

        $this->syncService
            ->expects($this->exactly(2))
            ->method('pushLocalToZebra')
            ->willReturnOnConsecutiveCalls($remoteTimesheet1, $remoteTimesheet2);

        $this->commandTester->setInputs(['Confirm all']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pushed', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Created timesheet in Zebra', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Pushed 2 timesheet(s)', $this->commandTester->getDisplay());
    }

    public function testPushMultipleTimesheetsWithAbort(): void
    {
        $date = Carbon::today();
        $localTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Test description 1',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $localTimesheet2 = TimesheetFactory::create(
            $this->activity,
            'Test description 2',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$localTimesheet1, $localTimesheet2]);

        $this->syncService
            ->expects($this->never())
            ->method('pushLocalToZebra');

        $this->commandTester->setInputs(['Abort']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pushed', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Push cancelled', $this->commandTester->getDisplay());
    }

    public function testPushWithAllDoNotSyncTimesheets(): void
    {
        $date = Carbon::today();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null, // zebraId
            null, // updatedAt
            null, // uuid
            true // doNotSync
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$localTimesheet]);

        $this->syncService
            ->expects($this->never())
            ->method('pushLocalToZebra');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No syncable timesheets found', $this->commandTester->getDisplay());
        $this->assertStringContainsString('doNotSync', $this->commandTester->getDisplay());
    }

    public function testPushMultipleTimesheetsWithConfirmEach(): void
    {
        $date = Carbon::today();
        $localTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Test description 1',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $localTimesheet2 = TimesheetFactory::create(
            $this->activity,
            'Test description 2',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $remoteTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Test description 1',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()
        );

        $this->localRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$localTimesheet1, $localTimesheet2]);

        $this->syncService
            ->expects($this->once())
            ->method('pushLocalToZebra')
            ->with($localTimesheet1, null)
            ->willReturn($remoteTimesheet1);

        $this->commandTester->setInputs(['Confirm each', 'yes', 'no']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pushed', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Created timesheet in Zebra', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Pushed 1 timesheet(s)', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Skipped 1 timesheet(s)', $this->commandTester->getDisplay());
    }
}
