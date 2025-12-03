<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\ActivityOrProjectAutocompletion;
use Tcrawf\Zebra\Command\TimesheetCreateCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\User\User;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class TimesheetCreateCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $timesheetRepository;
    private ActivityRepositoryInterface&MockObject $activityRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private ActivityOrProjectAutocompletion&MockObject $autocompletion;
    private TimesheetCreateCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;
    private User $user;

    protected function setUp(): void
    {
        $this->timesheetRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->activityRepository = $this->createMock(ActivityRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->autocompletion = $this->createMock(ActivityOrProjectAutocompletion::class);

        $this->command = new TimesheetCreateCommand(
            $this->timesheetRepository,
            $this->activityRepository,
            $this->userRepository,
            $this->autocompletion
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
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $this->user = new User(
            id: 1,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$this->role]
        );
    }

    public function testExecuteWithValidInput(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('alias')
            ->willReturn($this->activity);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($this->user);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($timesheet) {
                return $timesheet->activity->entityKey->toString() === $this->activity->entityKey->toString()
                    && $timesheet->time === 1.5
                    && $timesheet->description === 'Test Description';
            }));

        $this->commandTester->execute([
            'activity' => 'alias',
            'description' => 'Test Description',
            'time' => '1.5',
            '--role' => '1',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet created successfully', $this->commandTester->getDisplay());
    }

    public function testExecuteWithIndividualFlag(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('alias')
            ->willReturn($this->activity);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($timesheet) {
                return $timesheet->individualAction === true;
            }));

        $this->commandTester->execute([
            'activity' => 'alias',
            'description' => 'Test Description',
            'time' => '1.5',
            '--individual' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithInvalidTime(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('alias')
            ->willReturn($this->activity);

        $this->commandTester->execute([
            'activity' => 'alias',
            'description' => 'Test Description',
            'time' => '1.3', // Not a multiple of 0.25
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('must be a multiple of 0.25', $this->commandTester->getDisplay());
    }

    public function testExecuteWithActivityNotFound(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('nonexistent')
            ->willReturn(null);

        $this->activityRepository
            ->expects($this->once())
            ->method('searchByAlias')
            ->with('nonexistent')
            ->willReturn([]);

        $this->commandTester->execute([
            'activity' => 'nonexistent',
            'description' => 'Test Description',
            'time' => '1.5',
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    public function testExecuteWithDateOption(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('alias')
            ->willReturn($this->activity);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($this->user);

        $this->timesheetRepository
            ->expects($this->once())
            ->method('save');

        $this->commandTester->execute([
            'activity' => 'alias',
            'description' => 'Test Description',
            'time' => '1.5',
            '--date' => '2024-01-15',
            '--role' => '1',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}
