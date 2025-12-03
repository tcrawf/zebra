<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Tcrawf\Zebra\Client\ZebraApiException;

/**
 * Interface for timesheet API service.
 * Defines the contract for interacting with the Zebra timesheet API.
 */
interface TimesheetApiServiceInterface
{
    /**
     * Create a new timesheet entry via POST.
     *
     * @param array<string, mixed> $data Timesheet data (project_id, activity_id, role_id,
     *                                    description, client_description, time, date)
     * @return array<string, mixed> API response data
     * @throws ZebraApiException
     */
    public function create(array $data): array;

    /**
     * Update an existing timesheet via PUT.
     *
     * @param int $id The Zebra timesheet ID
     * @param array<string, mixed> $data Timesheet data to update
     * @return array<string, mixed> API response data
     * @throws ZebraApiException
     */
    public function update(int $id, array $data): array;

    /**
     * Delete a timesheet via DELETE.
     *
     * @param int $id The Zebra timesheet ID
     * @return void
     * @throws ZebraApiException
     */
    public function delete(int $id): void;

    /**
     * Fetch all timesheets with optional filters.
     *
     * @param array<string, mixed> $filters Optional filters (start_date, end_date, users[], etc.)
     * @return array<int, array<string, mixed>> Array of timesheet data indexed by ID
     * @throws ZebraApiException
     */
    public function fetchAll(array $filters = []): array;

    /**
     * Fetch a single timesheet by ID.
     *
     * @param int $id The Zebra timesheet ID
     * @return array<string, mixed> Timesheet data
     * @throws ZebraApiException
     */
    public function fetchById(int $id): array;
}
