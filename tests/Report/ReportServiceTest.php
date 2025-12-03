<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Report;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Report\ReportService;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;

class ReportServiceTest extends TestCase
{
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private TimezoneFormatter&MockObject $timezoneFormatter;
    private ReportService $reportService;
    private Activity $activity;
    private Role $role;
    private Project $project;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->timezoneFormatter = $this->createMock(TimezoneFormatter::class);
        $this->reportService = new ReportService($this->projectRepository, $this->timezoneFormatter);

        $projectEntityKey = EntityKey::zebra(100);
        $this->activity = new Activity(EntityKey::zebra(1), 'Test Activity', 'Description', $projectEntityKey);
        $this->role = new Role(1, null, 'Developer');
        $this->project = new Project($projectEntityKey, 'Test Project', 'Description', 1);

        $this->projectRepository
            ->method('get')
            ->with($projectEntityKey)
            ->willReturn($this->project);
    }

    public function testGenerateReportWithSingleIssueKey(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Frame with 1 hour (3600 seconds) and single issue key
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123'
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);

        $this->assertEquals(3600, $report['time']);
        $this->assertCount(1, $report['projects']);
        $this->assertEquals(3600, $report['projects'][0]['time']);
        $this->assertCount(1, $report['projects'][0]['activities']);
        $this->assertEquals(3600, $report['projects'][0]['activities'][0]['time']);
        $this->assertCount(1, $report['projects'][0]['activities'][0]['issueKeys']);
        $this->assertEquals('PROJ-123', $report['projects'][0]['activities'][0]['issueKeys'][0]['issueKey']);
        $this->assertEquals(3600, $report['projects'][0]['activities'][0]['issueKeys'][0]['time']);
    }

    public function testGenerateReportWithMultipleIssueKeysSplitsTimeProRata(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Frame with 1 hour (3600 seconds) and 2 issue keys
        // Should split to 1800 seconds each
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);

        // Total time should be 3600 seconds (not duplicated)
        $this->assertEquals(3600, $report['time']);
        $this->assertEquals(3600, $report['projects'][0]['time']);
        $this->assertEquals(3600, $report['projects'][0]['activities'][0]['time']);

        // Should have 2 issue keys
        $issueKeys = $report['projects'][0]['activities'][0]['issueKeys'];
        $this->assertCount(2, $issueKeys);

        // Find issue keys
        $proj123 = null;
        $proj456 = null;
        foreach ($issueKeys as $issueKeyEntry) {
            if ($issueKeyEntry['issueKey'] === 'PROJ-123') {
                $proj123 = $issueKeyEntry;
            } elseif ($issueKeyEntry['issueKey'] === 'PROJ-456') {
                $proj456 = $issueKeyEntry;
            }
        }

        $this->assertNotNull($proj123, 'PROJ-123 should be found');
        $this->assertNotNull($proj456, 'PROJ-456 should be found');

        // Each should get 1800 seconds (split equally)
        $this->assertEquals(1800, $proj123['time']);
        $this->assertEquals(1800, $proj456['time']);

        // Sum of issue key times should equal total
        $sumOfIssueKeyTimes = $proj123['time'] + $proj456['time'];
        $this->assertEquals($report['time'], $sumOfIssueKeyTimes);
    }

    public function testGenerateReportWithThreeIssueKeysSplitsTimeProRata(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Frame with 3601 seconds and 3 issue keys
        // Should split to 1200, 1200, 1201 (remainder goes to first)
        $startTime = Carbon::now()->subSeconds(3601);
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123, PROJ-456, and PROJ-789'
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);

        // Total time should be 3601 seconds
        $this->assertEquals(3601, $report['time']);

        // Should have 3 issue keys
        $issueKeys = $report['projects'][0]['activities'][0]['issueKeys'];
        $this->assertCount(3, $issueKeys);

        // Find issue keys
        $times = [];
        foreach ($issueKeys as $issueKeyEntry) {
            $times[$issueKeyEntry['issueKey']] = $issueKeyEntry['time'];
        }

        // First issue key should get remainder (1200 + 1 = 1201)
        // Others get 1200 each
        $this->assertContains(1201, $times);
        $this->assertContains(1200, $times);

        // Sum should equal total
        $sum = array_sum($times);
        $this->assertEquals(3601, $sum);
        $this->assertEquals($report['time'], $sum);
    }

    public function testGenerateReportByIssueKeyWithMultipleIssueKeysGroupsTogether(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Frame with 1 hour (3600 seconds) and 2 issue keys
        // Should be grouped as a single group with both issue keys
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        $report = $this->reportService->generateReportByIssueKey([$frame], $from, $to);

        // Total time should be 3600 seconds (not duplicated)
        $this->assertEquals(3600, $report['time']);

        // Should have 1 group (not split by individual issue keys)
        $this->assertCount(1, $report['issueKeys']);

        $group = $report['issueKeys'][0];

        // Group should contain both issue keys
        $this->assertCount(2, $group['issueKeys']);
        $this->assertContains('PROJ-123', $group['issueKeys']);
        $this->assertContains('PROJ-456', $group['issueKeys']);

        // Group should have full time (not split)
        $this->assertEquals(3600, $group['time']);
        $this->assertEquals(3600, $group['activity']['time']);

        // Activity should match
        $this->assertEquals('Test Activity', $group['activity']['name']);
    }

    public function testGenerateReportByIssueKeyGroupsFramesWithSameIssueKeySetDifferentOrder(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Frame 1: PROJ-123, PROJ-456
        $startTime1 = Carbon::now()->subHours(2);
        $stopTime1 = Carbon::now()->subHour();
        $frame1 = new Frame(
            Uuid::random(),
            $startTime1,
            $stopTime1,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        // Frame 2: PROJ-456, PROJ-123 (same set, different order)
        $startTime2 = Carbon::now()->subHour();
        $stopTime2 = Carbon::now();
        $frame2 = new Frame(
            Uuid::random(),
            $startTime2,
            $stopTime2,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-456 and PROJ-123'
        );

        $report = $this->reportService->generateReportByIssueKey([$frame1, $frame2], $from, $to);

        // Total time should be 7200 seconds (3600 + 3600)
        $this->assertEquals(7200, $report['time']);

        // Should have 1 group (same issue key set, same activity)
        $this->assertCount(1, $report['issueKeys']);

        $group = $report['issueKeys'][0];

        // Group should contain both issue keys
        $this->assertCount(2, $group['issueKeys']);
        $this->assertContains('PROJ-123', $group['issueKeys']);
        $this->assertContains('PROJ-456', $group['issueKeys']);

        // Group should have combined time
        $this->assertEquals(7200, $group['time']);
        $this->assertEquals(7200, $group['activity']['time']);
    }

    public function testGenerateReportByIssueKeyDoesNotGroupFramesWithDifferentIssueKeySets(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Frame 1: PROJ-123, PROJ-456
        $startTime1 = Carbon::now()->subHours(2);
        $stopTime1 = Carbon::now()->subHour();
        $frame1 = new Frame(
            Uuid::random(),
            $startTime1,
            $stopTime1,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        // Frame 2: PROJ-123, PROJ-789 (different set)
        $startTime2 = Carbon::now()->subHour();
        $stopTime2 = Carbon::now();
        $frame2 = new Frame(
            Uuid::random(),
            $startTime2,
            $stopTime2,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-789'
        );

        $report = $this->reportService->generateReportByIssueKey([$frame1, $frame2], $from, $to);

        // Total time should be 7200 seconds (3600 + 3600)
        $this->assertEquals(7200, $report['time']);

        // Should have 2 groups (different issue key sets)
        $this->assertCount(2, $report['issueKeys']);

        // Find groups
        $group1 = null;
        $group2 = null;
        foreach ($report['issueKeys'] as $group) {
            if (in_array('PROJ-456', $group['issueKeys'], true)) {
                $group1 = $group;
            } elseif (in_array('PROJ-789', $group['issueKeys'], true)) {
                $group2 = $group;
            }
        }

        $this->assertNotNull($group1, 'Group with PROJ-456 should be found');
        $this->assertNotNull($group2, 'Group with PROJ-789 should be found');

        // Each group should have its own time
        $this->assertEquals(3600, $group1['time']);
        $this->assertEquals(3600, $group2['time']);

        // Group 1 should have PROJ-123 and PROJ-456
        $this->assertCount(2, $group1['issueKeys']);
        $this->assertContains('PROJ-123', $group1['issueKeys']);
        $this->assertContains('PROJ-456', $group1['issueKeys']);

        // Group 2 should have PROJ-123 and PROJ-789
        $this->assertCount(2, $group2['issueKeys']);
        $this->assertContains('PROJ-123', $group2['issueKeys']);
        $this->assertContains('PROJ-789', $group2['issueKeys']);
    }

    public function testGenerateReportByIssueKeyDoesNotGroupFramesWithDifferentActivities(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        $projectEntityKey = EntityKey::zebra(100);
        $activity1 = new Activity(EntityKey::zebra(1), 'Activity 1', 'Description', $projectEntityKey);
        $activity2 = new Activity(EntityKey::zebra(2), 'Activity 2', 'Description', $projectEntityKey);

        // Frame 1: PROJ-123, PROJ-456 with Activity 1
        $startTime1 = Carbon::now()->subHours(2);
        $stopTime1 = Carbon::now()->subHour();
        $frame1 = new Frame(
            Uuid::random(),
            $startTime1,
            $stopTime1,
            $activity1,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        // Frame 2: PROJ-123, PROJ-456 with Activity 2 (same issue keys, different activity)
        $startTime2 = Carbon::now()->subHour();
        $stopTime2 = Carbon::now();
        $frame2 = new Frame(
            Uuid::random(),
            $startTime2,
            $stopTime2,
            $activity2,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        $report = $this->reportService->generateReportByIssueKey([$frame1, $frame2], $from, $to);

        // Total time should be 7200 seconds (3600 + 3600)
        $this->assertEquals(7200, $report['time']);

        // Should have 2 groups (same issue key set but different activities)
        $this->assertCount(2, $report['issueKeys']);

        // Find groups by activity name
        $group1 = null;
        $group2 = null;
        foreach ($report['issueKeys'] as $group) {
            if ($group['activity']['name'] === 'Activity 1') {
                $group1 = $group;
            } elseif ($group['activity']['name'] === 'Activity 2') {
                $group2 = $group;
            }
        }

        $this->assertNotNull($group1, 'Group with Activity 1 should be found');
        $this->assertNotNull($group2, 'Group with Activity 2 should be found');

        // Each group should have its own time
        $this->assertEquals(3600, $group1['time']);
        $this->assertEquals(3600, $group2['time']);

        // Both groups should have the same issue keys
        $this->assertCount(2, $group1['issueKeys']);
        $this->assertContains('PROJ-123', $group1['issueKeys']);
        $this->assertContains('PROJ-456', $group1['issueKeys']);

        $this->assertCount(2, $group2['issueKeys']);
        $this->assertContains('PROJ-123', $group2['issueKeys']);
        $this->assertContains('PROJ-456', $group2['issueKeys']);
    }

    public function testGenerateReportWithMultipleFramesAndMultipleIssueKeys(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Frame 1: 3600 seconds with 2 issue keys (1800 each)
        $startTime1 = Carbon::now()->subHours(2);
        $stopTime1 = Carbon::now()->subHour();
        $frame1 = new Frame(
            Uuid::random(),
            $startTime1,
            $stopTime1,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        // Frame 2: 1800 seconds with 1 issue key (1800 total)
        $startTime2 = Carbon::now()->subHour();
        $stopTime2 = Carbon::now()->subMinutes(30);
        $frame2 = new Frame(
            Uuid::random(),
            $startTime2,
            $stopTime2,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123'
        );

        $report = $this->reportService->generateReport([$frame1, $frame2], $from, $to);

        // Total time should be 5400 seconds (3600 + 1800)
        $this->assertEquals(5400, $report['time']);

        // Find PROJ-123: should get 1800 from frame1 + 1800 from frame2 = 3600
        // Find PROJ-456: should get 1800 from frame1
        $issueKeys = $report['projects'][0]['activities'][0]['issueKeys'];
        $proj123 = null;
        $proj456 = null;
        foreach ($issueKeys as $issueKeyEntry) {
            if ($issueKeyEntry['issueKey'] === 'PROJ-123') {
                $proj123 = $issueKeyEntry;
            } elseif ($issueKeyEntry['issueKey'] === 'PROJ-456') {
                $proj456 = $issueKeyEntry;
            }
        }

        $this->assertNotNull($proj123, 'PROJ-123 should be found');
        $this->assertNotNull($proj456, 'PROJ-456 should be found');

        $this->assertEquals(3600, $proj123['time']); // 1800 + 1800
        $this->assertEquals(1800, $proj456['time']); // 1800

        // Sum should equal total
        $sum = $proj123['time'] + $proj456['time'];
        $this->assertEquals(5400, $sum);
        $this->assertEquals($report['time'], $sum);
    }

    public function testFormatPlainText(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123'
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);

        $fromLocal = Carbon::now()->subDay();
        $toLocal = Carbon::now();
        $this->timezoneFormatter
            ->method('toLocal')
            ->willReturnCallback(function ($carbon) use ($from, $to, $fromLocal, $toLocal) {
                if ($carbon->eq($from)) {
                    return $fromLocal;
                }
                return $toLocal;
            });

        $lines = $this->reportService->formatPlainText($report);

        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('Test Project', implode("\n", $lines));
        $this->assertStringContainsString('Test Activity', implode("\n", $lines));
        $this->assertStringContainsString('Total:', implode("\n", $lines));
    }

    public function testFormatJson(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123'
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);
        $json = $this->reportService->formatJson($report);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('timespan', $decoded);
        $this->assertArrayHasKey('projects', $decoded);
        $this->assertArrayHasKey('time', $decoded);
        $this->assertIsString($decoded['timespan']['from']);
        $this->assertIsString($decoded['timespan']['to']);
    }

    public function testFormatCsv(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123'
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);
        $csv = $this->reportService->formatCsv($report);

        $this->assertIsString($csv);
        $lines = explode("\n", $csv);
        $this->assertStringContainsString('from,to,project,activity,issue_key,time', $lines[0]);
        $this->assertGreaterThan(1, count($lines));
    }

    public function testFormatPlainTextByIssueKey(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        $report = $this->reportService->generateReportByIssueKey([$frame], $from, $to);

        $fromLocal = Carbon::now()->subDay();
        $toLocal = Carbon::now();
        $this->timezoneFormatter
            ->method('toLocal')
            ->willReturnCallback(function ($carbon) use ($from, $to, $fromLocal, $toLocal) {
                if ($carbon->eq($from)) {
                    return $fromLocal;
                }
                return $toLocal;
            });

        $lines = $this->reportService->formatPlainTextByIssueKey($report);

        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('PROJ-123', implode("\n", $lines));
        $this->assertStringContainsString('Total:', implode("\n", $lines));
    }

    public function testFormatCsvByIssueKey(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Working on PROJ-123 and PROJ-456'
        );

        $report = $this->reportService->generateReportByIssueKey([$frame], $from, $to);
        $csv = $this->reportService->formatCsvByIssueKey($report);

        $this->assertIsString($csv);
        $lines = explode("\n", $csv);
        $this->assertStringContainsString('from,to,issue_key,activity,time', $lines[0]);
        $this->assertGreaterThan(1, count($lines));
        $this->assertStringContainsString('PROJ-123', $csv);
        $this->assertStringContainsString('PROJ-456', $csv);
    }

    public function testGenerateReportSkipsActiveFrames(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $startTime = Carbon::now()->subHour();
        $activeFrame = new Frame(
            Uuid::random(),
            $startTime,
            null,
            $this->activity,
            false,
            $this->role,
            'Active frame'
        );
        $completedFrame = new Frame(
            Uuid::random(),
            Carbon::now()->subHours(2),
            Carbon::now()->subHour(),
            $this->activity,
            false,
            $this->role,
            'Completed frame'
        );

        $report = $this->reportService->generateReport([$activeFrame, $completedFrame], $from, $to);

        // Should only include completed frame (3600 seconds)
        $this->assertEquals(3600, $report['time']);
    }

    public function testGenerateReportWithNoIssueKeys(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'No issue keys'
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);

        $this->assertEquals(3600, $report['time']);
        $issueKeys = $report['projects'][0]['activities'][0]['issueKeys'];
        $this->assertCount(1, $issueKeys);
        $this->assertEquals('(no issue key)', $issueKeys[0]['issueKey']);
    }

    public function testGenerateReportWithMultipleProjects(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $projectEntityKey1 = EntityKey::zebra(100);
        $projectEntityKey2 = EntityKey::zebra(200);
        $activity1 = new Activity(EntityKey::zebra(1), 'Activity 1', '', $projectEntityKey1);
        $activity2 = new Activity(EntityKey::zebra(2), 'Activity 2', '', $projectEntityKey2);
        $project1 = new Project($projectEntityKey1, 'Project 1', '', 1);
        $project2 = new Project($projectEntityKey2, 'Project 2', '', 1);

        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->projectRepository
            ->method('get')
            ->willReturnCallback(function ($key) use ($projectEntityKey1, $projectEntityKey2, $project1, $project2) {
                if ($key->toString() === $projectEntityKey1->toString()) {
                    return $project1;
                }
                if ($key->toString() === $projectEntityKey2->toString()) {
                    return $project2;
                }
                return null;
            });

        $this->reportService = new ReportService($this->projectRepository, $this->timezoneFormatter);

        $frame1 = new Frame(
            Uuid::random(),
            Carbon::now()->subHour(),
            Carbon::now(),
            $activity1,
            false,
            $this->role
        );
        $frame2 = new Frame(
            Uuid::random(),
            Carbon::now()->subHour(),
            Carbon::now(),
            $activity2,
            false,
            $this->role
        );

        $report = $this->reportService->generateReport([$frame1, $frame2], $from, $to);

        $this->assertEquals(7200, $report['time']);
        $this->assertCount(2, $report['projects']);
    }

    public function testGenerateReportWithProjectNotFound(): void
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $projectEntityKey = EntityKey::zebra(999);
        $activity = new Activity(EntityKey::zebra(1), 'Activity', '', $projectEntityKey);

        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->projectRepository
            ->method('get')
            ->willReturn(null);

        $this->reportService = new ReportService($this->projectRepository, $this->timezoneFormatter);

        $frame = new Frame(
            Uuid::random(),
            Carbon::now()->subHour(),
            Carbon::now(),
            $activity,
            false,
            $this->role
        );

        $report = $this->reportService->generateReport([$frame], $from, $to);

        $this->assertEquals(3600, $report['time']);
        $this->assertStringContainsString('Project', $report['projects'][0]['name']);
    }
}
