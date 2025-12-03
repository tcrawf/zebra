<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\LogCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;

class LogCommandTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private TimezoneFormatter $timezoneFormatter;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private LogCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;
    private Project $project;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->timezoneFormatter = new TimezoneFormatter();
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);

        $autocompletion = new \Tcrawf\Zebra\Command\Autocompletion\ProjectAutocompletion(
            $this->projectRepository
        );
        $this->command = new LogCommand(
            $this->frameRepository,
            $this->timezoneFormatter,
            $this->projectRepository,
            $autocompletion
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
        $this->assertEquals('log', $this->command->getName());
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

        $this->commandTester->execute(['--day' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Test Activity', $output);
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

        $this->commandTester->execute(['--day' => true, '--current' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithNoCurrentFrame(): void
    {
        $this->frameRepository
            ->method('filter')
            ->willReturn([]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--day' => true, '--no-current' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithJsonOutput(): void
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

        $this->commandTester->execute(['--day' => true, '--csv' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithReverseOption(): void
    {
        $now = Carbon::now()->utc();
        $day1 = $now->copy()->startOfDay();
        $day2 = $now->copy()->subDay()->startOfDay();

        // Create frames for two different days
        $day1Frame1Uuid = Uuid::random();
        $day1Frame1 = new Frame(
            $day1Frame1Uuid,
            $day1->copy()->addHours(9),
            $day1->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'Day 1 frame 1'
        );

        $day1Frame2Uuid = Uuid::random();
        $day1Frame2 = new Frame(
            $day1Frame2Uuid,
            $day1->copy()->addHours(11),
            $day1->copy()->addHours(12),
            $this->activity,
            false,
            $this->role,
            'Day 1 frame 2'
        );

        $day2FrameUuid = Uuid::random();
        $day2Frame = new Frame(
            $day2FrameUuid,
            $day2->copy()->addHours(9),
            $day2->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'Day 2 frame'
        );

        $this->frameRepository
            ->method('filter')
            ->willReturn([$day1Frame1, $day1Frame2, $day2Frame]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--reverse' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Verify frames within each day are chronological (oldest first)
        // Day 1: Frame1 (09:00) should appear before Frame2 (11:00)
        $day1Frame1Pos = strpos($output, (string) $day1Frame1Uuid);
        $day1Frame2Pos = strpos($output, (string) $day1Frame2Uuid);
        $this->assertNotFalse($day1Frame1Pos, 'Day 1 Frame1 UUID should be in output');
        $this->assertNotFalse($day1Frame2Pos, 'Day 1 Frame2 UUID should be in output');
        $this->assertLessThan(
            $day1Frame2Pos,
            $day1Frame1Pos,
            'Day 1 Frame1 should appear before Frame2 (chronological order)'
        );

        // Verify days are in reverse chronological order (newest first)
        // Day 1 (today) should appear before Day 2 (yesterday)
        $day1Pos = strpos($output, (string) $day1Frame1Uuid);
        $day2Pos = strpos($output, (string) $day2FrameUuid);
        $this->assertNotFalse($day1Pos, 'Day 1 should be in output');
        $this->assertNotFalse($day2Pos, 'Day 2 should be in output');
        $this->assertLessThan($day2Pos, $day1Pos, 'Day 1 should appear before Day 2 (reverse chronological order)');
    }

    public function testExecuteWithDefaultOrder(): void
    {
        $now = Carbon::now()->utc();
        $day1 = $now->copy()->subDay()->startOfDay();
        $day2 = $now->copy()->startOfDay();

        // Create frames for two different days
        $day1FrameUuid = Uuid::random();
        $day1Frame = new Frame(
            $day1FrameUuid,
            $day1->copy()->addHours(9),
            $day1->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'Day 1 frame'
        );

        $day2FrameUuid = Uuid::random();
        $day2Frame = new Frame(
            $day2FrameUuid,
            $day2->copy()->addHours(9),
            $day2->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'Day 2 frame'
        );

        $this->frameRepository
            ->method('filter')
            ->willReturn([$day1Frame, $day2Frame]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        // Execute without any reverse flags (default behavior)
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Verify days are in chronological order (oldest first) by default
        // Day 1 (yesterday) should appear before Day 2 (today)
        $day1Pos = strpos($output, (string) $day1FrameUuid);
        $day2Pos = strpos($output, (string) $day2FrameUuid);
        $this->assertNotFalse($day1Pos, 'Day 1 should be in output');
        $this->assertNotFalse($day2Pos, 'Day 2 should be in output');
        $this->assertLessThan($day2Pos, $day1Pos, 'Day 1 should appear before Day 2 (chronological order by default)');
    }

    public function testExecuteWithNoReverseOption(): void
    {
        $now = Carbon::now()->utc();
        $day1 = $now->copy()->subDay()->startOfDay();
        $day2 = $now->copy()->startOfDay();

        // Create frames for two different days
        $day1FrameUuid = Uuid::random();
        $day1Frame = new Frame(
            $day1FrameUuid,
            $day1->copy()->addHours(9),
            $day1->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'Day 1 frame'
        );

        $day2Frame1Uuid = Uuid::random();
        $day2Frame1 = new Frame(
            $day2Frame1Uuid,
            $day2->copy()->addHours(9),
            $day2->copy()->addHours(10),
            $this->activity,
            false,
            $this->role,
            'Day 2 frame 1'
        );

        $day2Frame2Uuid = Uuid::random();
        $day2Frame2 = new Frame(
            $day2Frame2Uuid,
            $day2->copy()->addHours(11),
            $day2->copy()->addHours(12),
            $this->activity,
            false,
            $this->role,
            'Day 2 frame 2'
        );

        $this->frameRepository
            ->method('filter')
            ->willReturn([$day1Frame, $day2Frame1, $day2Frame2]);
        $this->frameRepository
            ->method('getCurrent')
            ->willReturn(null);

        $this->commandTester->execute(['--no-reverse' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Verify frames within each day are chronological (oldest first)
        // Day 2: Frame1 (09:00) should appear before Frame2 (11:00)
        $day2Frame1Pos = strpos($output, (string) $day2Frame1Uuid);
        $day2Frame2Pos = strpos($output, (string) $day2Frame2Uuid);
        $this->assertNotFalse($day2Frame1Pos, 'Day 2 Frame1 UUID should be in output');
        $this->assertNotFalse($day2Frame2Pos, 'Day 2 Frame2 UUID should be in output');
        $this->assertLessThan(
            $day2Frame2Pos,
            $day2Frame1Pos,
            'Day 2 Frame1 should appear before Frame2 (chronological order)'
        );

        // Verify days are in chronological order (oldest first)
        // Day 1 (yesterday) should appear before Day 2 (today)
        $day1Pos = strpos($output, (string) $day1FrameUuid);
        $day2Pos = strpos($output, (string) $day2Frame1Uuid);
        $this->assertNotFalse($day1Pos, 'Day 1 should be in output');
        $this->assertNotFalse($day2Pos, 'Day 2 should be in output');
        $this->assertLessThan($day2Pos, $day1Pos, 'Day 1 should appear before Day 2 (chronological order)');
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
