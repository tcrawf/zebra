<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timezone;

use Carbon\CarbonInterface;

/**
 * Centralized timezone formatting service.
 * Handles conversion between UTC (storage) and local timezone (display/input).
 */
class TimezoneFormatter
{
    /**
     * Convert a UTC Carbon instance to the system/local timezone for display.
     *
     * @param CarbonInterface $utcTime The time in UTC
     * @return CarbonInterface The time in system timezone
     */
    public function toLocal(CarbonInterface $utcTime): CarbonInterface
    {
        return $utcTime->copy()->setTimezone($this->getSystemTimezone());
    }

    /**
     * Convert a Carbon instance from any timezone to UTC for storage.
     * If the time is already in UTC, this is a no-op (but still returns a copy).
     *
     * @param CarbonInterface $time The time in any timezone
     * @return CarbonInterface The time in UTC
     */
    public function toUtc(CarbonInterface $time): CarbonInterface
    {
        return $time->copy()->utc();
    }

    /**
     * Parse a string datetime in the local/system timezone, then convert to UTC.
     * This ensures that strings without timezone information are interpreted
     * in the user's local timezone rather than PHP's default.
     *
     * @param string $timeString The datetime string to parse
     * @return CarbonInterface The parsed time in UTC
     */
    public function parseLocalToUtc(string $timeString): CarbonInterface
    {
        $localTimezone = $this->getSystemTimezone();
        return \Carbon\Carbon::parse($timeString, $localTimezone)->utc();
    }

    /**
     * Format a UTC Carbon instance as a string in local timezone.
     *
     * @param CarbonInterface $utcTime The time in UTC
     * @param string $format The format string (defaults to ISO8601)
     * @return string The formatted time string in local timezone
     */
    public function formatLocal(CarbonInterface $utcTime, string $format = 'c'): string
    {
        return $this->toLocal($utcTime)->format($format);
    }

    /**
     * Get the system timezone using PHP-native methods.
     * Checks the TZ environment variable first, then falls back to PHP's configured timezone.
     * This approach is cross-platform and avoids file system operations.
     *
     * Note: After bootstrap.php runs, TZ should be set and date_default_timezone_get()
     * should return the correct timezone. We check multiple sources for robustness.
     *
     * @return string The timezone identifier (e.g., 'Europe/Zurich', 'America/New_York')
     */
    protected function getSystemTimezone(): string
    {
        // Check $_SERVER first (dotenv populates this)
        if (isset($_SERVER['TZ']) && $_SERVER['TZ'] !== '' && $this->isValidTimezone($_SERVER['TZ'])) {
            return $_SERVER['TZ'];
        }

        // Try TZ environment variable via getenv() (cross-platform, PHP-native)
        $tz = getenv('TZ');
        if ($tz !== false && $tz !== '' && $this->isValidTimezone($tz)) {
            return $tz;
        }

        // Fallback to PHP's default timezone (set by bootstrap.php from TZ)
        // This should be the correct timezone after bootstrap.php runs
        return date_default_timezone_get();
    }

    /**
     * Check if a timezone identifier is valid.
     *
     * @param string $timezone The timezone identifier to validate
     * @return bool True if valid, false otherwise
     */
    protected function isValidTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
