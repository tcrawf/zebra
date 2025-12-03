<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\CarbonInterface;

/**
 * Base interface for timesheet repository read operations.
 * Contains common read methods shared by both local and Zebra timesheet repositories.
 */
interface TimesheetRepositoryReadInterface
{
    /**
     * Get all timesheets.
     *
     * @return array<TimesheetInterface>
     */
    public function all(): array;

    /**
     * Get a timesheet by its UUID.
     *
     * @param string $uuid The timesheet UUID
     * @return TimesheetInterface|null
     */
    public function get(string $uuid): ?TimesheetInterface;

    /**
     * Get timesheets by Zebra ID.
     *
     * @param int $zebraId The Zebra API ID
     * @return TimesheetInterface|null
     */
    public function getByZebraId(int $zebraId): ?TimesheetInterface;

    /**
     * Get timesheets within a date range.
     * Filters timesheets by their date.
     *
     * @param CarbonInterface|int|string $from The start date (inclusive)
     * @param CarbonInterface|int|string|null $to The end date (inclusive, optional)
     * @return array<TimesheetInterface>
     */
    public function getByDateRange(CarbonInterface|int|string $from, CarbonInterface|int|string|null $to = null): array;

    /**
     * Get timesheets by frame UUIDs.
     * Returns all timesheets that reference any of the provided frame UUIDs.
     *
     * @param array<string> $frameUuids Array of frame UUIDs to search for
     * @return array<TimesheetInterface>
     */
    public function getByFrameUuids(array $frameUuids): array;
}
