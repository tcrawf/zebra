<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\CarbonInterface;

/**
 * Interface for timesheet sync service.
 * Defines the contract for syncing timesheets bi-directionally between local storage and Zebra API.
 */
interface TimesheetSyncServiceInterface
{
    /**
     * Push a local timesheet to Zebra.
     * Creates or updates the timesheet in Zebra based on whether it has a zebraId.
     *
     * @param TimesheetInterface $timesheet The local timesheet to push
     * @param callable|null $confirmationCallback Optional callback to confirm updates.
     *                                            Should return true to proceed, false to cancel.
     *                                            Receives the timesheet as parameter.
     *                                            Only called for updates (when zebraId exists).
     *                                            If null and timesheet has zebraId, update will be cancelled.
     * @return TimesheetInterface|null The updated timesheet with zebraId and remote data,
     *                                 or null if cancelled
     */
    public function pushLocalToZebra(
        TimesheetInterface $timesheet,
        ?callable $confirmationCallback = null
    ): ?TimesheetInterface;

    /**
     * Pull timesheets from Zebra for a date range.
     *
     * @param CarbonInterface $from The start date (inclusive)
     * @param CarbonInterface|null $to The end date (inclusive, optional)
     * @return array<TimesheetInterface> Array of timesheets pulled from Zebra
     */
    public function pullFromZebra(CarbonInterface $from, CarbonInterface|null $to = null): array;
}
