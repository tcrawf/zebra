<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

/**
 * Interface for local timesheet repository.
 * Defines the contract for CRUD operations on local timesheets.
 */
interface LocalTimesheetRepositoryInterface extends TimesheetRepositoryReadInterface
{
    /**
     * Save a timesheet to storage.
     *
     * @param TimesheetInterface $timesheet
     * @return void
     */
    public function save(TimesheetInterface $timesheet): void;

    /**
     * Update an existing timesheet.
     *
     * @param TimesheetInterface $timesheet
     * @return void
     */
    public function update(TimesheetInterface $timesheet): void;

    /**
     * Remove a timesheet by UUID.
     *
     * @param string $uuid
     * @return void
     */
    public function remove(string $uuid): void;
}
