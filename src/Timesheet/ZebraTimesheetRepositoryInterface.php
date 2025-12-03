<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

/**
 * Interface for Zebra timesheet repository.
 * Defines the contract for retrieving and writing timesheets to Zebra API.
 */
interface ZebraTimesheetRepositoryInterface extends TimesheetRepositoryReadInterface
{
    /**
     * Create a new timesheet in Zebra via API.
     * POSTs the timesheet to Zebra and returns the updated timesheet with zebraId.
     *
     * @param TimesheetInterface $timesheet The timesheet to create
     * @return TimesheetInterface The updated timesheet with zebraId and remote data
     */
    public function create(TimesheetInterface $timesheet): TimesheetInterface;

    /**
     * Update an existing timesheet in Zebra via API.
     * PUTs updates to Zebra and returns the updated timesheet with remote data.
     *
     * @param TimesheetInterface $timesheet The timesheet to update (must have zebraId)
     * @param callable $confirmationCallback Callback to confirm the update.
     *                                       Must return true to proceed, false to cancel.
     *                                       Receives the timesheet as parameter.
     * @return TimesheetInterface|null The updated timesheet with remote data, or null if cancelled
     */
    public function update(TimesheetInterface $timesheet, callable $confirmationCallback): ?TimesheetInterface;

    /**
     * Delete a timesheet from Zebra via API.
     *
     * @param int $zebraId The Zebra timesheet ID
     * @param callable $confirmationCallback Callback to confirm the deletion.
     *                                       Must return true to proceed, false to cancel.
     *                                       Receives the zebraId as parameter.
     * @return bool True if deleted, false if cancelled
     */
    public function delete(int $zebraId, callable $confirmationCallback): bool;

    /**
     * Fetch raw API data for a timesheet by Zebra ID.
     * Returns the full API response structure without conversion to TimesheetInterface.
     *
     * @param int $zebraId The Zebra timesheet ID
     * @return array<string, mixed> Raw API response data
     * @throws \Tcrawf\Zebra\Exception\TrackException
     */
    public function fetchRawApiData(int $zebraId): array;
}
