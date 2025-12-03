<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Tcrawf\Zebra\Timesheet\TimesheetDateHelper;

class TimesheetDateHelperTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        // Store original timezone
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        // Restore original timezone
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function testParseDateStringWithDateOnlyFormat(): void
    {
        $date = TimesheetDateHelper::parseDateString('2025-12-01');
        $this->assertInstanceOf(CarbonInterface::class, $date);
        $this->assertEquals('2025-12-01', $date->format('Y-m-d'));
        $this->assertEquals('Europe/Zurich', $date->timezone->getName());
        $this->assertEquals('00:00:00', $date->format('H:i:s'));
    }

    public function testParseDateStringPreservesCalendarDateAcrossTimezones(): void
    {
        // Set timezone to CET (UTC+1)
        date_default_timezone_set('Europe/Zurich');

        $date = TimesheetDateHelper::parseDateString('2025-12-01');
        $this->assertEquals('2025-12-01', $date->format('Y-m-d'));
        $this->assertEquals('Europe/Zurich', $date->timezone->getName());
    }

    public function testParseDateStringWithInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format');
        TimesheetDateHelper::parseDateString('invalid-date');
    }

    public function testGetTodayUtc(): void
    {
        date_default_timezone_set('Europe/Zurich');
        $today = TimesheetDateHelper::getTodayUtc();

        $this->assertInstanceOf(CarbonInterface::class, $today);
        $this->assertEquals('Europe/Zurich', $today->timezone->getName());
        $this->assertEquals('00:00:00', $today->format('H:i:s'));

        // Should match today's date in local timezone
        $localToday = Carbon::today('Europe/Zurich');
        $this->assertEquals($localToday->format('Y-m-d'), $today->format('Y-m-d'));
    }

    public function testGetYesterdayUtc(): void
    {
        date_default_timezone_set('Europe/Zurich');
        $yesterday = TimesheetDateHelper::getYesterdayUtc();

        $this->assertInstanceOf(CarbonInterface::class, $yesterday);
        $this->assertEquals('Europe/Zurich', $yesterday->timezone->getName());
        $this->assertEquals('00:00:00', $yesterday->format('H:i:s'));

        // Should match yesterday's date in local timezone
        $localYesterday = Carbon::yesterday('Europe/Zurich');
        $this->assertEquals($localYesterday->format('Y-m-d'), $yesterday->format('Y-m-d'));
    }

    public function testParseDateInputWithDateOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('date', 'd', InputOption::VALUE_OPTIONAL),
            new InputOption('yesterday', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--date' => '2025-12-01'], $definition);
        $date = TimesheetDateHelper::parseDateInput($input);

        $this->assertEquals('2025-12-01', $date->format('Y-m-d'));
        $this->assertEquals('Europe/Zurich', $date->timezone->getName());
    }

    public function testParseDateInputWithYesterdayOption(): void
    {
        date_default_timezone_set('Europe/Zurich');
        $definition = new InputDefinition([
            new InputOption('date', 'd', InputOption::VALUE_OPTIONAL),
            new InputOption('yesterday', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--yesterday' => true], $definition);
        $date = TimesheetDateHelper::parseDateInput($input);

        $localYesterday = Carbon::yesterday('Europe/Zurich');
        $this->assertEquals($localYesterday->format('Y-m-d'), $date->format('Y-m-d'));
    }

    public function testParseDateInputDefaultsToToday(): void
    {
        date_default_timezone_set('Europe/Zurich');
        $definition = new InputDefinition([
            new InputOption('date', 'd', InputOption::VALUE_OPTIONAL),
            new InputOption('yesterday', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput([], $definition);
        $date = TimesheetDateHelper::parseDateInput($input);

        $localToday = Carbon::today('Europe/Zurich');
        $this->assertEquals($localToday->format('Y-m-d'), $date->format('Y-m-d'));
    }

    public function testParseDateInputThrowsExceptionWhenBothDateAndYesterdayProvided(): void
    {
        $definition = new InputDefinition([
            new InputOption('date', 'd', InputOption::VALUE_OPTIONAL),
            new InputOption('yesterday', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--date' => '2025-12-01', '--yesterday' => true], $definition);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot specify both');
        TimesheetDateHelper::parseDateInput($input);
    }

    public function testFormatDateForDisplay(): void
    {
        date_default_timezone_set('Europe/Zurich');
        $utcDate = Carbon::parse('2025-12-01', 'UTC')->startOfDay();
        $formatted = TimesheetDateHelper::formatDateForDisplay($utcDate);

        $this->assertStringContainsString('2025-12-01', $formatted);
        $this->assertStringContainsString('December', $formatted);
    }

    public function testFormatDateForStorage(): void
    {
        $utcDate = Carbon::parse('2025-12-01', 'UTC')->startOfDay();
        $formatted = TimesheetDateHelper::formatDateForStorage($utcDate);

        $this->assertEquals('2025-12-01', $formatted);
    }

    public function testFormatDateForApi(): void
    {
        $utcDate = Carbon::parse('2025-12-01', 'UTC')->startOfDay();
        $formatted = TimesheetDateHelper::formatDateForApi($utcDate);

        $this->assertEquals('2025-12-01', $formatted);
    }

    public function testDateOnlyStringPreservesCalendarDateAcrossTimezoneBoundaries(): void
    {
        // Test edge case: date near timezone boundary
        date_default_timezone_set('Pacific/Auckland'); // UTC+12

        $date = TimesheetDateHelper::parseDateString('2025-12-01');
        $this->assertEquals('2025-12-01', $date->format('Y-m-d'));
        $this->assertEquals('Europe/Zurich', $date->timezone->getName());
    }

    public function testTodayPreservesCalendarDateAcrossTimezoneBoundaries(): void
    {
        // Test edge case: date near timezone boundary
        date_default_timezone_set('Pacific/Auckland'); // UTC+12

        $today = TimesheetDateHelper::getTodayUtc();
        $localToday = Carbon::today('Pacific/Auckland');

        // The calendar date should match, even if UTC time is different
        $this->assertEquals($localToday->format('Y-m-d'), $today->format('Y-m-d'));
    }
}
