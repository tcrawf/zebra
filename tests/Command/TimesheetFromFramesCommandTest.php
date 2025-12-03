<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\TimesheetFromFramesCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Report\ReportServiceInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetFromFramesCommandTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private ReportServiceInterface&MockObject $reportService;
    private LocalTimesheetRepositoryInterface&MockObject $timesheetRepository;
    private TimesheetFromFramesCommand $command;
    private CommandTester $commandTester;
    private Activity $activity1;
    private Activity $activity2;
    private Role $role;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->reportService = $this->createMock(ReportServiceInterface::class);
        $this->timesheetRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);

        $this->command = new TimesheetFromFramesCommand(
            $this->frameRepository,
            $this->reportService,
            $this->timesheetRepository
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity1 = new Activity(
            EntityKey::zebra(123),
            'Test Activity 1',
            'Activity Description 1',
            EntityKey::zebra(100),
            'activity-123'
        );
        $this->activity2 = new Activity(
            EntityKey::zebra(456),
            'Test Activity 2',
            'Activity Description 2',
            EntityKey::zebra(200),
            'activity-456'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    public function testDuplicateDetectionByFrameUuidsOnly(): void
    {
        $date = Carbon::today();
        $frameUuid1 = Uuid::random();
        $frameUuid2 = Uuid::random();

        // Create frames
        $frame1 = new Frame(
            $frameUuid1,
            Carbon::now()->subHours(2),
            Carbon::now()->subHour(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: Working on issue'
        );
        $frame2 = new Frame(
            $frameUuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: More work'
        );

        // Create an existing timesheet with overlapping frame UUIDs but different activity
        $existingTimesheet = TimesheetFactory::create(
            $this->activity2, // Different activity
            'Different description',
            null,
            2.0,
            $date,
            $this->role,
            false,
            [$frameUuid1->getHex()], // Same frame UUID
            null
        );

        // Mock frame repository filter to return frames
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->with(
                [],
                [],
                [],
                [],
                $this->anything(),
                $this->anything(),
                true
            )
            ->willReturn([$frame1, $frame2]);

        // Mock report service
        $this->reportService
            ->expects($this->once())
            ->method('generateReportByIssueKey')
            ->willReturn([
                'time' => 7200, // 2 hours
                'timespan' => [
                    'from' => $date->copy()->startOfDay(),
                    'to' => $date->copy()->endOfDay(),
                ],
                'issueKeys' => [
                    [
                        'issueKeys' => ['PROJ-123'],
                        'time' => 7200,
                        'activity' => [
                            'entityKey' => [
                                'source' => 'zebra',
                                'id' => 123,
                            ],
                            'name' => 'Test Activity 1',
                        ],
                    ],
                ],
                'frames' => [$frame1, $frame2],
            ]);

        // Mock timesheet repository to return existing timesheet
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([$existingTimesheet]);

        // Should update existing timesheet with merged frame UUIDs (preserving time)
        $this->timesheetRepository
            ->expects($this->never())
            ->method('save');
        $this->timesheetRepository
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($timesheet) use ($frameUuid1, $frameUuid2, $existingTimesheet) {
                // Verify time is preserved
                return $timesheet->time === $existingTimesheet->time
                    && $timesheet->uuid === $existingTimesheet->uuid
                    && in_array($frameUuid1->getHex(), $timesheet->frameUuids, true)
                    && in_array($frameUuid2->getHex(), $timesheet->frameUuids, true);
            }));

        $this->commandTester->execute(['--date' => $date->format('Y-m-d')]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Updated timesheet', $display);
        $this->assertStringContainsString('added', $display);
    }

    public function testNoDuplicateWhenFrameUuidsDoNotOverlap(): void
    {
        $date = Carbon::today();
        $frameUuid1 = Uuid::random();
        $frameUuid2 = Uuid::random();
        $frameUuid3 = Uuid::random();

        // Create frames
        $frame1 = new Frame(
            $frameUuid1,
            Carbon::now()->subHours(2),
            Carbon::now()->subHour(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: Working on issue'
        );
        $frame2 = new Frame(
            $frameUuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: More work'
        );

        // Create an existing timesheet with different frame UUIDs
        $existingTimesheet = TimesheetFactory::create(
            $this->activity1, // Same activity
            'Same description', // Same description
            null,
            2.0,
            $date,
            $this->role,
            false,
            [$frameUuid3->getHex()], // Different frame UUID
            null
        );

        // Mock frame repository filter to return frames
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->with(
                [],
                [],
                [],
                [],
                $this->anything(),
                $this->anything(),
                true
            )
            ->willReturn([$frame1, $frame2]);

        // Mock report service
        $this->reportService
            ->expects($this->once())
            ->method('generateReportByIssueKey')
            ->willReturn([
                'time' => 7200, // 2 hours
                'timespan' => [
                    'from' => $date->copy()->startOfDay(),
                    'to' => $date->copy()->endOfDay(),
                ],
                'issueKeys' => [
                    [
                        'issueKeys' => ['PROJ-123'],
                        'time' => 7200,
                        'activity' => [
                            'entityKey' => [
                                'source' => 'zebra',
                                'id' => 123,
                            ],
                            'name' => 'Test Activity 1',
                        ],
                    ],
                ],
                'frames' => [$frame1, $frame2],
            ]);

        // Mock timesheet repository to return existing timesheet
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([$existingTimesheet]);

        // Should save new timesheet because frame UUIDs don't overlap
        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($timesheet) use ($frameUuid1, $frameUuid2) {
                return in_array($frameUuid1->getHex(), $timesheet->frameUuids, true)
                    && in_array($frameUuid2->getHex(), $timesheet->frameUuids, true);
            }));

        $this->commandTester->execute(['--date' => $date->format('Y-m-d')]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringNotContainsString('Skipping duplicate timesheet', $display);
    }

    public function testDuplicateDetectionWithEmptyFrameUuids(): void
    {
        $date = Carbon::today();
        $frameUuid1 = Uuid::random();

        // Create frame
        $frame1 = new Frame(
            $frameUuid1,
            Carbon::now()->subHours(2),
            Carbon::now()->subHour(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: Working on issue'
        );

        // Create an existing timesheet with empty frame UUIDs
        $existingTimesheet = TimesheetFactory::create(
            $this->activity1,
            'Same description',
            null,
            2.0,
            $date,
            $this->role,
            false,
            [], // Empty frame UUIDs
            null
        );

        // Mock frame repository filter to return frames
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->with(
                [],
                [],
                [],
                [],
                $this->anything(),
                $this->anything(),
                true
            )
            ->willReturn([$frame1]);

        // Mock report service
        $this->reportService
            ->expects($this->once())
            ->method('generateReportByIssueKey')
            ->willReturn([
                'time' => 3600, // 1 hour
                'timespan' => [
                    'from' => $date->copy()->startOfDay(),
                    'to' => $date->copy()->endOfDay(),
                ],
                'issueKeys' => [
                    [
                        'issueKeys' => ['PROJ-123'],
                        'time' => 3600,
                        'activity' => [
                            'entityKey' => [
                                'source' => 'zebra',
                                'id' => 123,
                            ],
                            'name' => 'Test Activity 1',
                        ],
                    ],
                ],
                'frames' => [$frame1],
            ]);

        // Mock timesheet repository to return existing timesheet
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([$existingTimesheet]);

        // Should save new timesheet because no frame UUIDs overlap (existing has empty array)
        $this->timesheetRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($timesheet) use ($frameUuid1) {
                return in_array($frameUuid1->getHex(), $timesheet->frameUuids, true);
            }));

        $this->commandTester->execute(['--date' => $date->format('Y-m-d')]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringNotContainsString('Skipping duplicate timesheet', $display);
    }

    public function testDuplicateDetectionWithPartialFrameUuidOverlap(): void
    {
        $date = Carbon::today();
        $frameUuid1 = Uuid::random();
        $frameUuid2 = Uuid::random();
        $frameUuid3 = Uuid::random();

        // Create frames
        $frame1 = new Frame(
            $frameUuid1,
            Carbon::now()->subHours(2),
            Carbon::now()->subHour(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: Working on issue'
        );
        $frame2 = new Frame(
            $frameUuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: More work'
        );

        // Create an existing timesheet with one overlapping frame UUID
        $existingTimesheet = TimesheetFactory::create(
            $this->activity2, // Different activity
            'Different description', // Different description
            null,
            1.0, // Different time
            $date,
            $this->role,
            false,
            [$frameUuid1->getHex(), $frameUuid3->getHex()], // One overlapping UUID
            null
        );

        // Mock frame repository filter to return frames
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->with(
                [],
                [],
                [],
                [],
                $this->anything(),
                $this->anything(),
                true
            )
            ->willReturn([$frame1, $frame2]);

        // Mock report service
        $this->reportService
            ->expects($this->once())
            ->method('generateReportByIssueKey')
            ->willReturn([
                'time' => 7200, // 2 hours
                'timespan' => [
                    'from' => $date->copy()->startOfDay(),
                    'to' => $date->copy()->endOfDay(),
                ],
                'issueKeys' => [
                    [
                        'issueKeys' => ['PROJ-123'],
                        'time' => 7200,
                        'activity' => [
                            'entityKey' => [
                                'source' => 'zebra',
                                'id' => 123,
                            ],
                            'name' => 'Test Activity 1',
                        ],
                    ],
                ],
                'frames' => [$frame1, $frame2],
            ]);

        // Mock timesheet repository to return existing timesheet
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([$existingTimesheet]);

        // Should update existing timesheet with merged frame UUIDs (preserving time)
        $this->timesheetRepository
            ->expects($this->never())
            ->method('save');
        $this->timesheetRepository
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($timesheet) use ($frameUuid1, $frameUuid2, $existingTimesheet) {
                // Verify time is preserved
                return $timesheet->time === $existingTimesheet->time
                    && $timesheet->uuid === $existingTimesheet->uuid
                    && in_array($frameUuid1->getHex(), $timesheet->frameUuids, true)
                    && in_array($frameUuid2->getHex(), $timesheet->frameUuids, true);
            }));

        $this->commandTester->execute(['--date' => $date->format('Y-m-d')]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Updated timesheet', $display);
        $this->assertStringContainsString('added', $display);
    }

    public function testSkipWhenAllFramesAlreadyExist(): void
    {
        $date = Carbon::today();
        $frameUuid1 = Uuid::random();
        $frameUuid2 = Uuid::random();

        // Create frames
        $frame1 = new Frame(
            $frameUuid1,
            Carbon::now()->subHours(2),
            Carbon::now()->subHour(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: Working on issue'
        );
        $frame2 = new Frame(
            $frameUuid2,
            Carbon::now()->subHour(),
            Carbon::now(),
            $this->activity1,
            false,
            $this->role,
            'PROJ-123: More work'
        );

        // Create an existing timesheet with all the same frame UUIDs
        $existingTimesheet = TimesheetFactory::create(
            $this->activity1,
            'Same description',
            null,
            2.0,
            $date,
            $this->role,
            false,
            [$frameUuid1->getHex(), $frameUuid2->getHex()], // All frames already exist
            null
        );

        // Mock frame repository filter to return frames
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->with(
                [],
                [],
                [],
                [],
                $this->anything(),
                $this->anything(),
                true
            )
            ->willReturn([$frame1, $frame2]);

        // Mock report service
        $this->reportService
            ->expects($this->once())
            ->method('generateReportByIssueKey')
            ->willReturn([
                'time' => 7200, // 2 hours
                'timespan' => [
                    'from' => $date->copy()->startOfDay(),
                    'to' => $date->copy()->endOfDay(),
                ],
                'issueKeys' => [
                    [
                        'issueKeys' => ['PROJ-123'],
                        'time' => 7200,
                        'activity' => [
                            'entityKey' => [
                                'source' => 'zebra',
                                'id' => 123,
                            ],
                            'name' => 'Test Activity 1',
                        ],
                    ],
                ],
                'frames' => [$frame1, $frame2],
            ]);

        // Mock timesheet repository to return existing timesheet
        $this->timesheetRepository
            ->expects($this->once())
            ->method('getByDateRange')
            ->with($date, $date)
            ->willReturn([$existingTimesheet]);

        // Should NOT update because all frames already exist
        $this->timesheetRepository
            ->expects($this->never())
            ->method('update');
        $this->timesheetRepository
            ->expects($this->never())
            ->method('save');

        $this->commandTester->execute(['--date' => $date->format('Y-m-d')]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Skipping duplicate timesheet', $display);
        // Verify that no update occurred (all frames already exist)
        $this->assertStringNotContainsString('Updated timesheet', $display);
    }
}
