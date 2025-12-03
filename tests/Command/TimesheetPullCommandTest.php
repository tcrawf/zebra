<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\TimesheetPullCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\TimesheetSyncServiceInterface;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;

class TimesheetPullCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $localRepository;
    private ZebraTimesheetRepositoryInterface&MockObject $zebraRepository;
    private TimesheetSyncServiceInterface&MockObject $syncService;
    private TimesheetAutocompletion&MockObject $timesheetAutocompletion;
    private TimesheetPullCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->localRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->zebraRepository = $this->createMock(ZebraTimesheetRepositoryInterface::class);
        $this->syncService = $this->createMock(TimesheetSyncServiceInterface::class);
        $this->timesheetAutocompletion = $this->createMock(TimesheetAutocompletion::class);

        $this->command = new TimesheetPullCommand(
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

    public function testPullWithNoTimesheets(): void
    {
        $this->zebraRepository
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
        $this->assertStringContainsString('No remote timesheets found', $this->commandTester->getDisplay());
    }

    public function testPullWithLocalNewerWarning(): void
    {
        $date = Carbon::today();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Local description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()
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
            Carbon::now()->subHour()
        );

        $pulledTimesheet = TimesheetFactory::create(
            $this->activity,
            'Remote description',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()->subHour()
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                }),
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                })
            )
            ->willReturn([$remoteTimesheet]);

        $this->localRepository
            ->expects($this->exactly(2))
            ->method('getByZebraId')
            ->with(12345)
            ->willReturn($localTimesheet);

        $this->syncService
            ->expects($this->once())
            ->method('pullFromZebra')
            ->with(
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                }),
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                })
            )
            ->willReturn([$pulledTimesheet]);

        $this->commandTester->setInputs(['Confirm each']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString('Timesheets to be pulled', $normalizedOutput);
        $this->assertStringContainsString('Local timesheet', $normalizedOutput);
        $this->assertStringContainsString('was modified after remote version', $normalizedOutput);
        $this->assertStringContainsString('Pulled 1 timesheet(s)', $normalizedOutput);
    }

    public function testPullWithForceFlagSuppressesWarning(): void
    {
        $date = Carbon::today();
        $localTimesheet = TimesheetFactory::create(
            $this->activity,
            'Local description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()
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
            Carbon::now()->subHour()
        );

        $pulledTimesheet = TimesheetFactory::create(
            $this->activity,
            'Remote description',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()->subHour()
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                }),
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                })
            )
            ->willReturn([$remoteTimesheet]);

        $this->localRepository
            ->expects($this->once())
            ->method('getByZebraId')
            ->with(12345)
            ->willReturn($localTimesheet);

        $this->syncService
            ->expects($this->once())
            ->method('pullFromZebra')
            ->with(
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                }),
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                })
            )
            ->willReturn([$pulledTimesheet]);

        $this->commandTester->execute(['--force' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringNotContainsString('was modified after remote version', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Pulled 1 timesheet(s)', $this->commandTester->getDisplay());
    }

    public function testPullWithDateOption(): void
    {
        $date = Carbon::parse('2024-01-15')->startOfDay();

        $this->zebraRepository
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

    public function testPullWithYesterdayOption(): void
    {
        $yesterday = Carbon::yesterday();

        $this->zebraRepository
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

    public function testPullWithInvalidDate(): void
    {
        $this->commandTester->execute(['--date' => 'invalid-date']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid date format', $this->commandTester->getDisplay());
    }

    public function testPullWithBothDateAndYesterdayOptions(): void
    {
        $this->commandTester->execute(['--date' => '2024-01-15', '--yesterday' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Cannot specify both', $this->commandTester->getDisplay());
    }

    public function testPullSuccessfully(): void
    {
        $date = Carbon::today();
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

        $pulledTimesheet = TimesheetFactory::create(
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

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with(
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                }),
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                })
            )
            ->willReturn([$remoteTimesheet]);

        $this->localRepository
            ->expects($this->exactly(2))
            ->method('getByZebraId')
            ->with(12345)
            ->willReturn(null);

        $this->syncService
            ->expects($this->once())
            ->method('pullFromZebra')
            ->with(
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                }),
                $this->callback(function ($d) {
                    return $d instanceof Carbon && $d->isToday();
                })
            )
            ->willReturn([$pulledTimesheet]);

        $this->commandTester->setInputs(['Confirm each']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pulled', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Pulled 1 timesheet(s)', $this->commandTester->getDisplay());
    }

    public function testPullMultipleTimesheetsWithConfirmAll(): void
    {
        $date = Carbon::today();
        $remoteTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Remote description 1',
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
            'Remote description 2',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12346,
            Carbon::now()
        );

        $pulledTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Remote description 1',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345,
            Carbon::now()
        );

        $pulledTimesheet2 = TimesheetFactory::create(
            $this->activity,
            'Remote description 2',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12346,
            Carbon::now()
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$remoteTimesheet1, $remoteTimesheet2]);

        $this->localRepository
            ->expects($this->exactly(4))
            ->method('getByZebraId')
            ->willReturnCallback(function ($zebraId) {
                return null;
            });

        $this->syncService
            ->expects($this->once())
            ->method('pullFromZebra')
            ->willReturn([$pulledTimesheet1, $pulledTimesheet2]);

        $this->commandTester->setInputs(['Confirm all']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pulled', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Pulled 2 timesheet(s)', $this->commandTester->getDisplay());
        $this->assertStringNotContainsString('was modified after remote version', $this->commandTester->getDisplay());
    }

    public function testPullMultipleTimesheetsWithAbort(): void
    {
        $date = Carbon::today();
        $remoteTimesheet1 = TimesheetFactory::create(
            $this->activity,
            'Remote description 1',
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
            'Remote description 2',
            null,
            3.0,
            $date,
            $this->role,
            false,
            [],
            12346,
            Carbon::now()
        );

        $this->zebraRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->willReturn([$remoteTimesheet1, $remoteTimesheet2]);

        $this->syncService
            ->expects($this->never())
            ->method('pullFromZebra');

        $this->commandTester->setInputs(['Abort']);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheets to be pulled', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Pull cancelled', $this->commandTester->getDisplay());
    }
}
