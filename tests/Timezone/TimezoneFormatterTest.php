<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timezone;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class TimezoneFormatterTest extends TestCase
{
    private TimezoneFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new TimezoneFormatter();
    }

    public function testToLocal(): void
    {
        $utcTime = Carbon::now()->utc();
        $localTime = $this->formatter->toLocal($utcTime);

        // Verify input is UTC
        $this->assertEquals('UTC', $utcTime->timezone->getName());

        // Verify output is in system timezone (may be UTC if system timezone is UTC)
        $reflection = new \ReflectionClass($this->formatter);
        $getSystemTimezoneMethod = $reflection->getMethod('getSystemTimezone');
        $getSystemTimezoneMethod->setAccessible(true);
        $systemTimezone = $getSystemTimezoneMethod->invoke($this->formatter);

        $this->assertEquals($systemTimezone, $localTime->timezone->getName());
        // Should return a copy, not the same instance
        $this->assertNotSame($utcTime, $localTime);
    }

    public function testToUtc(): void
    {
        $localTime = Carbon::now()->setTimezone('Europe/Zurich');
        $utcTime = $this->formatter->toUtc($localTime);

        $this->assertEquals('UTC', $utcTime->timezone->getName());
    }

    public function testToUtcWithUtcInput(): void
    {
        $utcTime = Carbon::now()->utc();
        $result = $this->formatter->toUtc($utcTime);

        $this->assertEquals('UTC', $result->timezone->getName());
        // Should return a copy, not the same instance
        $this->assertNotSame($utcTime, $result);
    }

    public function testParseLocalToUtc(): void
    {
        $timeString = '2024-01-01 12:00:00';
        $utcTime = $this->formatter->parseLocalToUtc($timeString);

        $this->assertEquals('UTC', $utcTime->timezone->getName());
        $this->assertInstanceOf(Carbon::class, $utcTime);
    }

    public function testFormatLocal(): void
    {
        $utcTime = Carbon::now()->utc();
        $formatted = $this->formatter->formatLocal($utcTime);

        $this->assertIsString($formatted);
        $this->assertNotEmpty($formatted);
    }

    public function testFormatLocalWithCustomFormat(): void
    {
        $utcTime = Carbon::create(2024, 1, 1, 12, 0, 0, 'UTC');
        $formatted = $this->formatter->formatLocal($utcTime, 'Y-m-d H:i:s');

        $this->assertIsString($formatted);
        $this->assertStringContainsString('2024-01-01', $formatted);
    }

    public function testGetSystemTimezone(): void
    {
        $reflection = new \ReflectionClass($this->formatter);
        $method = $reflection->getMethod('getSystemTimezone');
        $method->setAccessible(true);

        $timezone = $method->invoke($this->formatter);

        $this->assertIsString($timezone);
        $this->assertNotEmpty($timezone);
    }

    public function testIsValidTimezone(): void
    {
        $reflection = new \ReflectionClass($this->formatter);
        $method = $reflection->getMethod('isValidTimezone');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->formatter, 'Europe/Zurich'));
        $this->assertTrue($method->invoke($this->formatter, 'America/New_York'));
        $this->assertTrue($method->invoke($this->formatter, 'UTC'));
        $this->assertFalse($method->invoke($this->formatter, 'Invalid/Timezone'));
        $this->assertFalse($method->invoke($this->formatter, ''));
    }
}
