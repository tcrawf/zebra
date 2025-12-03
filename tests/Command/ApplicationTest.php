<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Tcrawf\Zebra\Command\Application;

class ApplicationTest extends TestCase
{
    private Application $application;
    private ApplicationTester $tester;
    private string $originalHome = '';

    protected function setUp(): void
    {
        // Set HOME environment variable for tests (required for ConfigFileStorage)
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . sys_get_temp_dir());

        $this->application = new Application();
        $this->tester = new ApplicationTester($this->application);
    }

    protected function tearDown(): void
    {
        // Restore HOME environment variable
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
    }

    public function testApplicationHasCommands(): void
    {
        $commands = $this->application->all();
        $this->assertGreaterThan(0, count($commands));
    }

    public function testApplicationHasStartCommand(): void
    {
        $this->assertTrue($this->application->has('start'));
    }

    public function testApplicationHasStopCommand(): void
    {
        $this->assertTrue($this->application->has('stop'));
    }

    public function testApplicationHasStatusCommand(): void
    {
        $this->assertTrue($this->application->has('status'));
    }

    public function testApplicationHasReportCommand(): void
    {
        $this->assertTrue($this->application->has('report'));
    }

    public function testApplicationHasTimesheetListCommand(): void
    {
        $this->assertTrue($this->application->has('timesheet:list'));
    }

    public function testApplicationHasTimesheetCreateCommand(): void
    {
        $this->assertTrue($this->application->has('timesheet:create'));
    }

    public function testApplicationHasTimesheetEditCommand(): void
    {
        $this->assertTrue($this->application->has('timesheet:edit'));
    }
}
