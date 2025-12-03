<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Symfony\Component\Console\Input\InputInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

/**
 * Centralized utility for handling timesheet dates.
 *
 * Timesheet dates represent calendar dates, not timestamps. A date like "2025-12-01"
 * should always represent December 1st, regardless of timezone.
 *
 * Storage Format: Always UTC, date-only (Y-m-d format), normalized to start of day (00:00:00 UTC)
 * Query Format: Always UTC dates for comparison
 * Display Format: Convert to local timezone for user-friendly display, but preserve calendar date
 */
class TimesheetDateHelper
{
    private static ?TimezoneFormatter $timezoneFormatter = null;

    /**
     * Parse date input from command options.
     * Handles --date option, --yesterday option (if it exists), or defaults to today.
     *
     * @param InputInterface $input The command input
     * @param string $dateOptionName The option name for date (default: 'date')
     * @param string|null $yesterdayOptionName The option name for yesterday (default: 'yesterday', null to disable)
     * @return CarbonInterface UTC date normalized to start of day
     */
    public static function parseDateInput(
        InputInterface $input,
        string $dateOptionName = 'date',
        ?string $yesterdayOptionName = 'yesterday'
    ): CarbonInterface {
        $dateStr = $input->getOption($dateOptionName);
        $yesterday = false;

        // Only check for yesterday option if it's enabled and exists in the command definition
        if ($yesterdayOptionName !== null && $input->hasOption($yesterdayOptionName)) {
            $yesterday = $input->getOption($yesterdayOptionName) === true;
        }

        if ($yesterday && $dateStr !== null) {
            throw new \InvalidArgumentException(
                "Cannot specify both --{$dateOptionName} and --{$yesterdayOptionName} options"
            );
        }

        if ($yesterday) {
            return self::getYesterdayUtc();
        }

        if ($dateStr !== null) {
            return self::parseDateString($dateStr);
        }

        return self::getTodayUtc();
    }

    /**
     * Get today's date in Europe/Zurich timezone (API timezone).
     * Gets today in local timezone, extracts date string, then parses as Europe/Zurich.
     *
     * @return CarbonInterface Europe/Zurich date normalized to start of day
     */
    public static function getTodayUtc(): CarbonInterface
    {
        $localTimezone = date_default_timezone_get();
        $localToday = Carbon::today($localTimezone);
        $dateString = $localToday->format('Y-m-d');
        return Carbon::parse($dateString, 'Europe/Zurich')->startOfDay();
    }

    /**
     * Get yesterday's date in Europe/Zurich timezone (API timezone).
     * Gets yesterday in local timezone, extracts date string, then parses as Europe/Zurich.
     *
     * @return CarbonInterface Europe/Zurich date normalized to start of day
     */
    public static function getYesterdayUtc(): CarbonInterface
    {
        $localTimezone = date_default_timezone_get();
        $localYesterday = Carbon::yesterday($localTimezone);
        $dateString = $localYesterday->format('Y-m-d');
        return Carbon::parse($dateString, 'Europe/Zurich')->startOfDay();
    }

    /**
     * Parse a date string, handling both date-only and datetime strings.
     *
     * Date-only strings (Y-m-d format) are parsed as Europe/Zurich calendar dates
     * to match the API timezone.
     *
     * Datetime strings are parsed in local timezone, then converted to Europe/Zurich.
     *
     * @param string $dateStr Date string (Y-m-d format or datetime string)
     * @return CarbonInterface Europe/Zurich date normalized to start of day
     * @throws \InvalidArgumentException If date string is invalid
     */
    public static function parseDateString(string $dateStr): CarbonInterface
    {
        // Check if it's a date-only string (Y-m-d format) - parse as Europe/Zurich calendar date
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            try {
                return Carbon::parse($dateStr, 'Europe/Zurich')->startOfDay();
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(
                    "Invalid date format: {$dateStr}. Use YYYY-MM-DD format.",
                    0,
                    $e
                );
            }
        }

        // Parse other strings in local timezone, then convert to Europe/Zurich
        if (self::$timezoneFormatter === null) {
            self::$timezoneFormatter = new TimezoneFormatter();
        }

        try {
            $utcDate = self::$timezoneFormatter->parseLocalToUtc($dateStr);
            return $utcDate->setTimezone('Europe/Zurich')->startOfDay();
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid date format: {$dateStr}. Use YYYY-MM-DD format.",
                0,
                $e
            );
        }
    }

    /**
     * Format a date for display in local timezone.
     * Converts Europe/Zurich date to local timezone for user-friendly display.
     *
     * @param CarbonInterface $date Europe/Zurich date
     * @return string Formatted date string (e.g., "Monday 01 December 2025 (2025-12-01)")
     */
    public static function formatDateForDisplay(CarbonInterface $date): string
    {
        $localTimezone = date_default_timezone_get();
        $localDate = $date->copy()->setTimezone($localTimezone);
        return sprintf('%s (%s)', $localDate->format('l d F Y'), $localDate->format('Y-m-d'));
    }

    /**
     * Format a date for storage (Y-m-d format).
     * Formats date from Europe/Zurich timezone.
     *
     * @param CarbonInterface $date Europe/Zurich date
     * @return string Date string in Y-m-d format
     */
    public static function formatDateForStorage(CarbonInterface $date): string
    {
        return $date->setTimezone('Europe/Zurich')->format('Y-m-d');
    }

    /**
     * Format a date for API queries (Y-m-d format).
     * Formats date from Europe/Zurich timezone (API timezone).
     *
     * @param CarbonInterface $date Europe/Zurich date
     * @return string Date string in Y-m-d format
     */
    public static function formatDateForApi(CarbonInterface $date): string
    {
        return $date->setTimezone('Europe/Zurich')->format('Y-m-d');
    }
}
