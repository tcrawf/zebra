<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\ReportCommand;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Report\ReportServiceInterface;

class ReportCommandTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private ReportServiceInterface&MockObject $reportService;
    private ReportCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->reportService = $this->createMock(ReportServiceInterface::class);
        $this->command = new ReportCommand($this->frameRepository, $this->reportService);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteWithNoFrames(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->willReturn([]);

        $this->reportService
            ->expects($this->once())
            ->method('generateReport')
            ->willReturn([]);

        $this->reportService
            ->expects($this->once())
            ->method('formatPlainText')
            ->with([])
            ->willReturn(['']);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithJsonFormat(): void
    {
        $report = ['test' => 'data'];
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->willReturn([]);

        $this->reportService
            ->expects($this->once())
            ->method('generateReport')
            ->willReturn($report);

        $this->reportService
            ->expects($this->once())
            ->method('formatJson')
            ->with($report)
            ->willReturn('{"test":"data"}');

        $this->commandTester->execute(['--json' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('{"test":"data"}', $this->commandTester->getDisplay());
    }

    public function testExecuteWithCsvFormat(): void
    {
        $report = ['test' => 'data'];
        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->willReturn([]);

        $this->reportService
            ->expects($this->once())
            ->method('generateReport')
            ->willReturn($report);

        $this->reportService
            ->expects($this->once())
            ->method('formatCsv')
            ->with($report)
            ->willReturn('test,data');

        $this->commandTester->execute(['--csv' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithDateRange(): void
    {
        $fromDate = Carbon::parse('2024-01-01')->utc();
        $toDate = Carbon::parse('2024-01-31')->utc();

        $emptyReport = [
            'timespan' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'projects' => [],
            'time' => 0,
        ];

        $this->frameRepository
            ->expects($this->once())
            ->method('filter')
            ->with(
                null, // projectIds - parseIntArray returns null for empty arrays
                [],
                null, // ignoreProjectIds - parseIntArray returns null for empty arrays
                [],
                $this->isInstanceOf(Carbon::class),
                $this->isInstanceOf(Carbon::class),
                true
            )
            ->willReturn([]);

        $this->frameRepository
            ->expects($this->never())
            ->method('getCurrent');

        $this->reportService
            ->expects($this->once())
            ->method('generateReport')
            ->willReturn($emptyReport);

        $this->reportService
            ->expects($this->once())
            ->method('formatPlainText')
            ->willReturn(['']);

        $this->commandTester->execute([
            '--from' => '2024-01-01',
            '--to' => '2024-01-31',
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}
