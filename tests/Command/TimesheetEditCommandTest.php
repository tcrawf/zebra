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
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\Timesheet;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetEditCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $timesheetRepository;
    private ZebraTimesheetRepositoryInterface&MockObject $zebraTimesheetRepository;
    private ActivityRepositoryInterface&MockObject $activityRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private TimesheetAutocompletion&MockObject $timesheetAutocompletion;
    private TimesheetEditCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Timesheet $timesheet;

    protected function setUp(): void
    {
        $this->timesheetRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->zebraTimesheetRepository = $this->createMock(ZebraTimesheetRepositoryInterface::class);
        $this->activityRepository = $this->createMock(ActivityRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->timesheetAutocompletion = $this->createMock(TimesheetAutocompletion::class);

        $this->command = new TimesheetEditCommand(
            $this->timesheetRepository,
            $this->zebraTimesheetRepository,
            $this->activityRepository,
            $this->userRepository,
            $this->timesheetAutocompletion
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
        $role = new \Tcrawf\Zebra\Role\Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $this->timesheet = TimesheetFactory::create(
            $this->activity,
            'Test Description',
            null,
            1.5,
            Carbon::today(),
            $role,
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
}
