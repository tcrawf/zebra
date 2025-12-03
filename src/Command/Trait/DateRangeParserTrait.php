<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

use Carbon\Carbon;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Trait for parsing date ranges from command input options.
 *
 * Supports various date shortcuts (year, month, week, day, today, yesterday)
 * and custom date ranges via --from and --to options.
 */
trait DateRangeParserTrait
{
    /**
     * Parse date range from input options.
     *
     * @param InputInterface $input
     * @return array{Carbon, Carbon}
     */
    private function parseDateRange(InputInterface $input): array
    {
        $now = Carbon::now();

        // Check for shortcuts first (mutually exclusive)
        // Use hasOption to check if option exists before calling getOption
        if ($input->hasOption('year') && $input->getOption('year')) {
            $from = $now->copy()->startOfYear();
            $to = $this->getYearEndDate($now);
        } elseif ($input->hasOption('month') && $input->getOption('month')) {
            $from = $now->copy()->startOfMonth();
            $to = $this->getMonthEndDate($now);
        } elseif ($input->hasOption('week') && $input->getOption('week')) {
            $from = $now->copy()->startOfWeek();
            $to = $this->getWeekEndDate($now);
        } elseif ($input->hasOption('yesterday') && $input->getOption('yesterday')) {
            $yesterday = $now->copy()->subDay();
            $from = $yesterday->copy()->startOfDay();
            $to = $yesterday->copy()->endOfDay();
        } elseif ($input->hasOption('day') && $input->getOption('day')) {
            $from = $now->copy()->startOfDay();
            $to = $this->getDayEndDate($now);
        } else {
            // Use --from and --to options
            $fromStr = $input->getOption('from');
            $toStr = $input->getOption('to');

            if ($fromStr !== null) {
                // Parse in local timezone, then convert to UTC
                $from = Carbon::parse($fromStr);
                if ($this->shouldParseDatesInUtc()) {
                    $from = $from->utc();
                }
            } else {
                // Calculate default from date
                $from = $this->getDefaultFromDate($now);
            }

            if ($toStr !== null) {
                // Parse in local timezone, then convert to UTC
                $to = Carbon::parse($toStr);
                if ($this->shouldParseDatesInUtc()) {
                    $to = $to->utc();
                }
            } else {
                // Calculate default to date
                $to = $this->getDefaultToDate($now);
            }
        }

        // Convert to UTC for comparison with frames stored in UTC
        return [$from->utc(), $to->utc()];
    }

    /**
     * Get end date for year shortcut.
     * Override to customize behavior (e.g., LogCommand uses addYear()->startOfYear()).
     *
     * @param Carbon $now
     * @return Carbon
     */
    protected function getYearEndDate(Carbon $now): Carbon
    {
        return $now->copy()->endOfYear();
    }

    /**
     * Get end date for month shortcut.
     * Override to customize behavior (e.g., LogCommand uses addMonth()->startOfMonth()).
     *
     * @param Carbon $now
     * @return Carbon
     */
    protected function getMonthEndDate(Carbon $now): Carbon
    {
        return $now->copy()->endOfMonth();
    }

    /**
     * Get end date for week shortcut.
     * Override to customize behavior (e.g., LogCommand uses addWeek()->startOfWeek()).
     *
     * @param Carbon $now
     * @return Carbon
     */
    protected function getWeekEndDate(Carbon $now): Carbon
    {
        return $now->copy()->endOfWeek();
    }

    /**
     * Get end date for day shortcut.
     * Override to customize behavior (e.g., LogCommand uses addDay()->startOfDay()).
     *
     * @param Carbon $now
     * @return Carbon
     */
    protected function getDayEndDate(Carbon $now): Carbon
    {
        return $now->copy()->endOfDay();
    }

    /**
     * Get default from date when --from is not provided.
     * Override to customize behavior.
     *
     * @param Carbon $now
     * @return Carbon
     */
    protected function getDefaultFromDate(Carbon $now): Carbon
    {
        // Default: 7 days ago, start of day
        return $now->copy()->subDays(7)->startOfDay();
    }

    /**
     * Get default to date when --to is not provided.
     * Override to customize behavior.
     *
     * @param Carbon $now
     * @return Carbon
     */
    protected function getDefaultToDate(Carbon $now): Carbon
    {
        // Default: end of day (or tomorrow start of day for LogCommand)
        return $now->copy()->endOfDay();
    }

    /**
     * Whether to parse date strings in UTC immediately.
     * LogCommand parses dates in local timezone then converts to UTC.
     * Other commands parse dates directly.
     *
     * @return bool
     */
    protected function shouldParseDatesInUtc(): bool
    {
        return false;
    }
}
