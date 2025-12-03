<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFileStorage;
use Tcrawf\Zebra\Timesheet\TimesheetFileStorageFactory;

class TimesheetFileStorageFactoryTest extends TestCase
{
    private string $originalHome;

    protected function setUp(): void
    {
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=/tmp');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
    }

    public function testCreateReturnsTimesheetFileStorageInstance(): void
    {
        $factory = new TimesheetFileStorageFactory();
        $storage = $factory->create('test.json');

        $this->assertInstanceOf(FileStorageInterface::class, $storage);
        $this->assertInstanceOf(TimesheetFileStorage::class, $storage);
    }

    public function testCreateWithDifferentFilenames(): void
    {
        $factory = new TimesheetFileStorageFactory();
        $storage1 = $factory->create('timesheets.json');
        $storage2 = $factory->create('timesheet_2024.json');

        $this->assertInstanceOf(TimesheetFileStorage::class, $storage1);
        $this->assertInstanceOf(TimesheetFileStorage::class, $storage2);
    }
}
