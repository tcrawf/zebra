<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\AggregateCommand;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Report\ReportServiceInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class AggregateCommandSecurityTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private ReportServiceInterface&MockObject $reportService;
    private TimezoneFormatter $timezoneFormatter;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private AggregateCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->reportService = $this->createMock(ReportServiceInterface::class);
        $this->timezoneFormatter = new TimezoneFormatter();
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        // Mock getCurrent to return null by default
        $this->frameRepository->method('getCurrent')->willReturn(null);
        // Mock project repository get to return null by default
        $this->projectRepository->method('get')->willReturn(null);

        $this->command = new AggregateCommand(
            $this->frameRepository,
            $this->reportService,
            $this->timezoneFormatter,
            $this->projectRepository
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    /**
     * Test that malicious PAGER environment variable is rejected.
     */
    public function testMaliciousPagerEnvironmentVariable(): void
    {
        // Set malicious PAGER environment variable
        $originalPager = $_ENV['PAGER'] ?? $_SERVER['PAGER'] ?? null;
        $_ENV['PAGER'] = 'less; rm -rf /';
        $_SERVER['PAGER'] = 'less; rm -rf /';

        try {
            $this->frameRepository->method('filter')->willReturn([]);
            $this->reportService->method('generateReport')->willReturn([
                'time' => 0,
                'timespan' => [
                    'from' => \Carbon\Carbon::now()->utc(),
                    'to' => \Carbon\Carbon::now()->utc(),
                ],
                'projects' => [],
            ]);

            // Execute command with pager option
            $this->commandTester->execute(['--pager' => true, '--day' => true]);

            // Command should complete successfully (malicious pager should be rejected and default used)
            $this->assertEquals(0, $this->commandTester->getStatusCode());
        } finally {
            // Restore original PAGER
            if ($originalPager !== null) {
                $_ENV['PAGER'] = $originalPager;
                $_SERVER['PAGER'] = $originalPager;
            } else {
                unset($_ENV['PAGER'], $_SERVER['PAGER']);
            }
        }
    }

    /**
     * Test that PAGER with shell metacharacters is rejected.
     */
    public function testPagerWithShellMetacharacters(): void
    {
        $maliciousPagers = [
            'less; echo "injected"',
            'less | cat',
            'less && rm -rf /',
            'less `whoami`',
            'less $(ls)',
            'less; cat /etc/passwd',
            '../bin/less',
            '/bin/less; rm',
        ];

        foreach ($maliciousPagers as $maliciousPager) {
            $originalPager = $_ENV['PAGER'] ?? $_SERVER['PAGER'] ?? null;
            $_ENV['PAGER'] = $maliciousPager;
            $_SERVER['PAGER'] = $maliciousPager;

            try {
                $this->frameRepository->method('filter')->willReturn([]);
                $this->reportService->method('generateReport')->willReturn([
                    'time' => 0,
                    'timespan' => [
                        'from' => \Carbon\Carbon::now()->utc(),
                        'to' => \Carbon\Carbon::now()->utc(),
                    ],
                    'projects' => [],
                ]);

                // Command should complete successfully (malicious pager should be rejected)
                $this->commandTester->execute(['--pager' => true, '--day' => true]);
                $this->assertEquals(
                    0,
                    $this->commandTester->getStatusCode(),
                    "Failed to reject malicious pager: {$maliciousPager}"
                );
            } finally {
                if ($originalPager !== null) {
                    $_ENV['PAGER'] = $originalPager;
                    $_SERVER['PAGER'] = $originalPager;
                } else {
                    unset($_ENV['PAGER'], $_SERVER['PAGER']);
                }
            }
        }
    }

    /**
     * Test that valid pager commands are accepted.
     */
    public function testValidPagerCommands(): void
    {
        $validPagers = ['less', 'more', 'most', 'pg', 'cat'];

        foreach ($validPagers as $validPager) {
            $originalPager = $_ENV['PAGER'] ?? $_SERVER['PAGER'] ?? null;
            $_ENV['PAGER'] = $validPager;
            $_SERVER['PAGER'] = $validPager;

            try {
                $this->frameRepository->method('filter')->willReturn([]);
                $this->reportService->method('generateReport')->willReturn([
                    'time' => 0,
                    'timespan' => [
                        'from' => \Carbon\Carbon::now()->utc(),
                        'to' => \Carbon\Carbon::now()->utc(),
                    ],
                    'projects' => [],
                ]);

                // Command should complete successfully with valid pager
                $this->commandTester->execute(['--pager' => true, '--day' => true]);
                $this->assertEquals(
                    0,
                    $this->commandTester->getStatusCode(),
                    "Failed to accept valid pager: {$validPager}"
                );
            } finally {
                if ($originalPager !== null) {
                    $_ENV['PAGER'] = $originalPager;
                    $_SERVER['PAGER'] = $originalPager;
                } else {
                    unset($_ENV['PAGER'], $_SERVER['PAGER']);
                }
            }
        }
    }
}
