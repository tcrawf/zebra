<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\TimesheetListCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetListCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $timesheetRepository;
    private FrameRepositoryInterface&MockObject $frameRepository;
    private TimesheetListCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;

    protected function setUp(): void
    {
        $this->timesheetRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->command = new TimesheetListCommand($this->timesheetRepository, $this->frameRepository);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity = new Activity(EntityKey::zebra(1), 'Test Activity', 'Description', EntityKey::zebra(100));
    }

    public function testExecuteWithNoTimesheets(): void
    {
        $date = Carbon::today();
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No timesheets found', $this->commandTester->getDisplay());
    }

    public function testExecuteWithTimesheets(): void
    {
        $date = Carbon::today();
        $uuid = Uuid::random();
        $role = new \Tcrawf\Zebra\Role\Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test Description',
            null,
            1.5,
            $date,
            $role,
            false,
            [],
            null,
            null,
            $uuid
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([$timesheet]);

        $this->frameRepository
            ->expects($this->atLeastOnce())
            ->method('filter')
            ->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = preg_replace('/\s+/', ' ', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Test Activity', $output);
    }

    public function testExecuteWithDateOption(): void
    {
        $date = Carbon::parse('2024-01-15');
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->willReturn([]);

        $this->commandTester->execute(['--date' => '2024-01-15']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithYesterdayOption(): void
    {
        $yesterday = Carbon::yesterday();
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($yesterday, $yesterday)
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->willReturn([]);

        $this->commandTester->execute(['--yesterday' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithInvalidDate(): void
    {
        $this->commandTester->execute(['--date' => 'invalid-date']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid date', $this->commandTester->getDisplay());
    }
}
