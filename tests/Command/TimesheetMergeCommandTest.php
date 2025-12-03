<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\TimesheetMergeCommand;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\TimesheetInterface;

class TimesheetMergeCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $repository;
    private TimesheetAutocompletion&MockObject $autocompletion;
    private TimesheetMergeCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Activity $differentActivity;
    private Role $role;
    private Role $differentRole;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->autocompletion = $this->createMock(TimesheetAutocompletion::class);

        $this->command = new TimesheetMergeCommand(
            $this->repository,
            $this->autocompletion
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
        $this->differentActivity = new Activity(
            EntityKey::zebra(456),
            'Different Activity',
            'Different Description',
            EntityKey::zebra(200),
            'activity-456'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $this->differentRole = new Role(2, null, 'Manager', 'Manager', 'employee', 'active');
    }

    public function testMergeWithLessThanTwoUuids(): void
    {
        $this->commandTester->execute(['timesheets' => ['uuid1']]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            'At least 2 timesheet UUIDs are required',
            $this->commandTester->getDisplay()
        );
    }

    public function testMergeWithNotFoundTimesheet(): void
    {
        $date = Carbon::today();
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

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                return null; // Second UUID not found
            });

        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, 'invalid-uuid']]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    public function testMergeWithDifferentActivities(): void
    {
        $date = Carbon::today();
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
        $timesheet2 = TimesheetFactory::create(
            $this->differentActivity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('different activity', $this->commandTester->getDisplay());
    }

    public function testMergeWithDifferentRoles(): void
    {
        $date = Carbon::today();
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
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->differentRole,
            false,
            []
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('different role', $this->commandTester->getDisplay());
    }

    public function testSuccessfulMerge(): void
    {
        $date = Carbon::today();
        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date,
            $this->role,
            false,
            ['frame-uuid-1']
        );
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->role,
            false,
            ['frame-uuid-2']
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TimesheetInterface $merged) use ($timesheet1) {
                return $merged->uuid === $timesheet1->uuid
                    && $merged->time === 3.0
                    && $merged->description === 'Description 1 | Description 2'
                    && $merged->activity->entityKey->toString() === $this->activity->entityKey->toString()
                    && $merged->role->id === $this->role->id
                    && in_array('frame-uuid-1', $merged->frameUuids, true)
                    && in_array('frame-uuid-2', $merged->frameUuids, true);
            }));

        $this->repository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet2->uuid);

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully merged', $this->commandTester->getDisplay());
    }

    public function testMergeWithForceFlag(): void
    {
        $date = Carbon::today();
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
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->repository
            ->expects($this->once())
            ->method('save');

        $this->repository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet2->uuid);

        $this->commandTester->execute([
            'timesheets' => [$timesheet1->uuid, $timesheet2->uuid],
            '--force' => true
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully merged', $this->commandTester->getDisplay());
    }

    public function testMergeCancelled(): void
    {
        $date = Carbon::today();
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
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->repository
            ->expects($this->never())
            ->method('save');

        $this->repository
            ->expects($this->never())
            ->method('remove');

        $this->commandTester->setInputs(['no']);
        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Merge cancelled', $this->commandTester->getDisplay());
    }

    public function testMergeWithClientDescriptions(): void
    {
        $date = Carbon::today();
        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            'Client Description 1',
            1.0,
            $date,
            $this->role,
            false,
            []
        );
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            'Client Description 2',
            2.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TimesheetInterface $merged) {
                return $merged->clientDescription === 'Client Description 1 | Client Description 2';
            }));

        $this->repository
            ->expects($this->once())
            ->method('remove')
            ->with($this->anything());

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testMergeWithIndividualAction(): void
    {
        $date = Carbon::today();
        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date,
            null,
            true, // individualAction
            []
        );
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            null,
            true, // individualAction
            []
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TimesheetInterface $merged) {
                return $merged->individualAction === true && $merged->role === null;
            }));

        $this->repository
            ->expects($this->once())
            ->method('remove')
            ->with($this->anything());

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testMergeWithZebraIdWarning(): void
    {
        $date = Carbon::today();
        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date,
            $this->role,
            false,
            [],
            12345 // Has zebraId
        );
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->role,
            false,
            []
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TimesheetInterface $merged) {
                return $merged->zebraId === null; // Should be null after merge
            }));

        $this->repository
            ->expects($this->once())
            ->method('remove')
            ->with($this->anything());

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // The output contains "lose sync status" - check for it with normalized whitespace
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString('lose sync status', $normalizedOutput);
    }

    public function testMergeThreeTimesheets(): void
    {
        $date = Carbon::today();
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
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->role,
            false,
            []
        );
        $timesheet3 = TimesheetFactory::create(
            $this->activity,
            'Description 3',
            null,
            1.5,
            $date,
            $this->role,
            false,
            []
        );

        $this->repository
            ->expects($this->exactly(3))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2, $timesheet3) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                if ($uuid === $timesheet3->uuid) {
                    return $timesheet3;
                }
                return null;
            });

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TimesheetInterface $merged) use ($timesheet1) {
                return $merged->uuid === $timesheet1->uuid
                    && $merged->time === 4.5
                    && $merged->description === 'Description 1 | Description 2 | Description 3';
            }));

        $this->repository
            ->expects($this->exactly(2))
            ->method('remove')
            ->with($this->logicalOr($timesheet2->uuid, $timesheet3->uuid));

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid, $timesheet3->uuid]]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully merged 3 timesheets', $this->commandTester->getDisplay());
    }

    public function testMergeWithDuplicateFrameUuids(): void
    {
        $date = Carbon::today();
        $timesheet1 = TimesheetFactory::create(
            $this->activity,
            'Description 1',
            null,
            1.0,
            $date,
            $this->role,
            false,
            ['frame-uuid-1', 'frame-uuid-2']
        );
        $timesheet2 = TimesheetFactory::create(
            $this->activity,
            'Description 2',
            null,
            2.0,
            $date,
            $this->role,
            false,
            ['frame-uuid-2', 'frame-uuid-3'] // frame-uuid-2 is duplicate
        );

        $this->repository
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($uuid) use ($timesheet1, $timesheet2) {
                if ($uuid === $timesheet1->uuid) {
                    return $timesheet1;
                }
                if ($uuid === $timesheet2->uuid) {
                    return $timesheet2;
                }
                return null;
            });

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TimesheetInterface $merged) {
                $frameUuids = $merged->frameUuids;
                // Should have unique frame UUIDs: frame-uuid-1, frame-uuid-2, frame-uuid-3
                return count($frameUuids) === 3
                    && in_array('frame-uuid-1', $frameUuids, true)
                    && in_array('frame-uuid-2', $frameUuids, true)
                    && in_array('frame-uuid-3', $frameUuids, true);
            }));

        $this->repository
            ->expects($this->once())
            ->method('remove')
            ->with($this->anything());

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['timesheets' => [$timesheet1->uuid, $timesheet2->uuid]]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}
