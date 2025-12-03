<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Trait;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Tcrawf\Zebra\Command\Trait\DateRangeParserTrait;

class DateRangeParserTraitTest extends TestCase
{
    private object $command;

    protected function setUp(): void
    {
        $this->command = new class {
            use DateRangeParserTrait;

            protected function shouldParseDatesInUtc(): bool
            {
                return false;
            }
        };
    }

    public function testParseDateRangeWithYearOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('year', 'y', InputOption::VALUE_NONE),
            new InputOption('from', 'f', InputOption::VALUE_OPTIONAL),
            new InputOption('to', 't', InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput(['--year' => true], $definition);
        [$from, $to] = $this->callPrivateMethod('parseDateRange', [$input]);

        $now = Carbon::now();
        $expectedFrom = $now->copy()->startOfYear()->utc()->timestamp;
        $expectedTo = $now->copy()->endOfYear()->utc()->timestamp;
        $this->assertEquals($expectedFrom, $from->timestamp);
        $this->assertEquals($expectedTo, $to->timestamp);
    }

    public function testParseDateRangeWithMonthOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('month', 'm', InputOption::VALUE_NONE),
            new InputOption('from', 'f', InputOption::VALUE_OPTIONAL),
            new InputOption('to', 't', InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput(['--month' => true], $definition);
        [$from, $to] = $this->callPrivateMethod('parseDateRange', [$input]);

        $now = Carbon::now();
        $expectedFrom = $now->copy()->startOfMonth()->utc()->timestamp;
        $expectedTo = $now->copy()->endOfMonth()->utc()->timestamp;
        $this->assertEquals($expectedFrom, $from->timestamp);
        $this->assertEquals($expectedTo, $to->timestamp);
    }

    public function testParseDateRangeWithWeekOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('week', 'w', InputOption::VALUE_NONE),
            new InputOption('from', 'f', InputOption::VALUE_OPTIONAL),
            new InputOption('to', 't', InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput(['--week' => true], $definition);
        [$from, $to] = $this->callPrivateMethod('parseDateRange', [$input]);

        $now = Carbon::now();
        $expectedFrom = $now->copy()->startOfWeek()->utc()->timestamp;
        $expectedTo = $now->copy()->endOfWeek()->utc()->timestamp;
        $this->assertEquals($expectedFrom, $from->timestamp);
        $this->assertEquals($expectedTo, $to->timestamp);
    }

    public function testParseDateRangeWithDayOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('day', 'd', InputOption::VALUE_NONE),
            new InputOption('from', 'f', InputOption::VALUE_OPTIONAL),
            new InputOption('to', 't', InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput(['--day' => true], $definition);
        [$from, $to] = $this->callPrivateMethod('parseDateRange', [$input]);

        $now = Carbon::now();
        $expectedFrom = $now->copy()->startOfDay()->utc()->timestamp;
        $expectedTo = $now->copy()->endOfDay()->utc()->timestamp;
        $this->assertEquals($expectedFrom, $from->timestamp);
        $this->assertEquals($expectedTo, $to->timestamp);
    }

    public function testParseDateRangeWithYesterdayOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('yesterday', null, InputOption::VALUE_NONE),
            new InputOption('from', 'f', InputOption::VALUE_OPTIONAL),
            new InputOption('to', 't', InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput(['--yesterday' => true], $definition);
        [$from, $to] = $this->callPrivateMethod('parseDateRange', [$input]);

        $yesterday = Carbon::now()->subDay();
        $expectedFrom = $yesterday->copy()->startOfDay()->utc()->timestamp;
        $expectedTo = $yesterday->copy()->endOfDay()->utc()->timestamp;
        $this->assertEquals($expectedFrom, $from->timestamp);
        $this->assertEquals($expectedTo, $to->timestamp);
    }

    public function testParseDateRangeWithFromAndToOptions(): void
    {
        $definition = new InputDefinition([
            new InputOption('from', 'f', InputOption::VALUE_OPTIONAL),
            new InputOption('to', 't', InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput([
            '--from' => '2024-01-01',
            '--to' => '2024-01-31',
        ], $definition);
        [$from, $to] = $this->callPrivateMethod('parseDateRange', [$input]);

        // Dates are parsed in local timezone then converted to UTC
        // Format in local timezone to get back the original date
        $localTimezone = date_default_timezone_get();
        $this->assertEquals('2024-01-01', $from->setTimezone($localTimezone)->format('Y-m-d'));
        $this->assertEquals('2024-01-31', $to->setTimezone($localTimezone)->format('Y-m-d'));
    }

    public function testParseDateRangeWithDefaults(): void
    {
        $definition = new InputDefinition([
            new InputOption('from', 'f', InputOption::VALUE_OPTIONAL),
            new InputOption('to', 't', InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput([], $definition);
        [$from, $to] = $this->callPrivateMethod('parseDateRange', [$input]);

        $now = Carbon::now();
        $expectedFrom = $now->copy()->subDays(7)->startOfDay()->utc();
        $expectedTo = $now->copy()->endOfDay()->utc();

        $this->assertEquals($expectedFrom->timestamp, $from->timestamp);
        $this->assertEquals($expectedTo->timestamp, $to->timestamp);
    }

    /**
     * Helper to call private methods for testing.
     */
    private function callPrivateMethod(string $methodName, array $args)
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->command, $args);
    }
}
