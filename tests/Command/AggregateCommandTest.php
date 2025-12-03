<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\AggregateCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Report\ReportServiceInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;

class AggregateCommandTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private ReportServiceInterface&MockObject $reportService;
    private TimezoneFormatter $timezoneFormatter;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private AggregateCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;
    private Project $project;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->reportService = $this->createMock(ReportServiceInterface::class);
        $this->timezoneFormatter = new TimezoneFormatter();
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);

        $this->command = new AggregateCommand(
            $this->frameRepository,
            $this->reportService,
            $this->timezoneFormatter,
            $this->projectRepository
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $projectEntityKey = EntityKey::zebra(100);
        $this->activity = new Activity(EntityKey::zebra(1), 'Test Activity', 'Description', $projectEntityKey);
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $this->project = new Project($projectEntityKey, 'Test Project', 'Description', 1);

        $this->projectRepository
            ->method('get')
            ->willReturn($this->project);
    }

    public function testCommandName(): void
    {
        $this->assertEquals('aggregate', $this->command->getName());
    }

    public function testCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testExecuteWithNoFrames(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithDayOption(): void
    {
        $now = Carbon::now()->utc();
        $from = $now->copy()->startOfDay();
        $to = $now->copy()->endOfDay();

        $frame = new Frame(
            Uuid::random(),
            $from->copy()->addHours(9),
            $from->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->frameRepository
            ->method('filter')
            ->willReturn([$frame]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->reportService
            ->method('generateReport')
            ->willReturn([
                'time' => 3600,
                'timespan' => [
                    'from' => $from,
                    'to' => $to,
                ],
                'projects' => [
                    [
                        'name' => 'Test Project',
                        'time' => 3600,
                        'activities' => [],
                    ],
                ],
            ]);

        $this->commandTester->execute(['--day' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        // Output may be empty if no frames match the day, or may contain formatted output
        // Just verify the command succeeds
    }

    public function testExecuteWithWeekOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--week' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithMonthOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--month' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithYearOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--year' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithYesterdayOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--yesterday' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithFromToOptions(): void
    {
        $from = Carbon::now()->subDays(7)->startOfDay()->utc();
        $to = Carbon::now()->endOfDay()->utc();

        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute([
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String(),
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithProjectFilter(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute([
            '--day' => true,
            '--project' => ['100'],
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithIgnoreProjectFilter(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute([
            '--day' => true,
            '--ignore-project' => ['100'],
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithIssueKeyFilter(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute([
            '--day' => true,
            '--issue-key' => ['PROJ-123'],
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithIgnoreIssueKeyFilter(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute([
            '--day' => true,
            '--ignore-issue-key' => ['PROJ-123'],
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithCurrentFrame(): void
    {
        $now = Carbon::now()->utc();
        $currentFrame = new Frame(
            Uuid::random(),
            $now->copy()->subHour(),
            null,
            $this->activity,
            false,
            $this->role,
            'Current frame'
        );

        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn($currentFrame);

        $this->reportService
            ->method('generateReport')
            ->willReturn([
                'time' => 0,
                'timespan' => [
                    'from' => $now->copy()->startOfDay(),
                    'to' => $now->copy()->endOfDay(),
                ],
                'projects' => [],
            ]);

        $this->commandTester->execute(['--day' => true, '--current' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithJsonOutput(): void
    {
        $now = Carbon::now()->utc();
        $from = $now->copy()->startOfDay();
        $to = $now->copy()->endOfDay();

        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true, '--json' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // JSON output should be valid JSON
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    public function testExecuteWithCsvOutput(): void
    {
        $now = Carbon::now()->utc();
        $from = $now->copy()->startOfDay();
        $to = $now->copy()->endOfDay();

        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true, '--csv' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithReverseOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true, '--reverse' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithNoReverseOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true, '--no-reverse' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithByIssueKeyOption(): void
    {
        $now = Carbon::now()->utc();
        $from = $now->copy()->startOfDay();
        $to = $now->copy()->endOfDay();

        $frame = new Frame(
            Uuid::random(),
            $from->copy()->addHours(9),
            $from->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'PROJ-123 Test description'
        );

        $this->frameRepository
            ->method('filter')
            ->willReturn([$frame]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->reportService
            ->method('generateReportByIssueKey')
            ->willReturn([
                'time' => 3600,
                'timespan' => [
                    'from' => $from,
                    'to' => $to,
                ],
                'issueKeys' => [],
            ]);

        $this->commandTester->execute(['--day' => true, '--by-issue-key' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithPagerOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true, '--pager' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithNoPagerOption(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true, '--no-pager' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteHandlesException(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willThrowException(new \RuntimeException('Test error'));

        $this->commandTester->execute(['--day' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('error', strtolower($output));
    }
}
