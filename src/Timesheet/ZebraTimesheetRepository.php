<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\Exception\TrackException;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;

/**
 * Repository for storing and retrieving Zebra timesheets via API.
 */
class ZebraTimesheetRepository implements ZebraTimesheetRepositoryInterface
{
    /**
     * @param TimesheetApiServiceInterface $apiService
     * @param ActivityRepositoryInterface $activityRepo
     * @param UserRepositoryInterface $userRepo
     */
    public function __construct(
        private readonly TimesheetApiServiceInterface $apiService,
        private readonly ActivityRepositoryInterface $activityRepo,
        private readonly UserRepositoryInterface $userRepo
    ) {
    }

    /**
     * Get all Zebra timesheets.
     *
     * @return array<TimesheetInterface>
     */
    public function all(): array
    {
        $apiData = $this->apiService->fetchAll();
        return $this->convertApiDataToTimesheets($apiData);
    }

    /**
     * Get a timesheet by its UUID.
     * Note: UUIDs are local-only, so this searches all timesheets for a matching UUID.
     * This is not efficient for large datasets but maintains interface compatibility.
     *
     * @param string $uuid The timesheet UUID
     * @return TimesheetInterface|null
     */
    public function get(string $uuid): ?TimesheetInterface
    {
        // UUIDs are local-only, so we can't query by UUID directly from API
        // We'd need to fetch all and search, which is inefficient
        // For now, return null as UUIDs are not stored in Zebra
        return null;
    }

    /**
     * Get timesheet by Zebra ID.
     *
     * @param int $zebraId The Zebra API ID
     * @return TimesheetInterface|null
     */
    public function getByZebraId(int $zebraId): ?TimesheetInterface
    {
        try {
            $apiData = $this->apiService->fetchById($zebraId);
            return TimesheetFactory::fromApiResponse($apiData, $this->activityRepo, $this->userRepo);
        } catch (ZebraApiException $e) {
            // Check if this is a 404 error (timesheet not found)
            $is404 = $e->getCode() === 404
                || str_contains($e->getMessage(), '404')
                || str_contains($e->getMessage(), 'not found');
            if ($is404) {
                // Return null to indicate not found (will be handled by caller)
                return null;
            }
            // Re-throw other ZebraApiExceptions
            throw $e;
        } catch (TrackException $e) {
            return null;
        }
    }

    /**
     * Get timesheets within a date range.
     *
     * @param CarbonInterface|int|string $from The start date (inclusive)
     * @param CarbonInterface|int|string|null $to The end date (inclusive, optional)
     * @return array<TimesheetInterface>
     */
    public function getByDateRange(CarbonInterface|int|string $from, CarbonInterface|int|string|null $to = null): array
    {
        // Convert input dates to Europe/Zurich timezone (API timezone)
        // If input is already a Carbon object, preserve the calendar date when setting timezone
        if ($from instanceof CarbonInterface) {
            $fromDate = $from->copy()->setTimezone('Europe/Zurich')->startOfDay();
        } else {
            $fromDate = $this->convertToCarbon($from)->setTimezone('Europe/Zurich')->startOfDay();
        }

        if ($to !== null) {
            if ($to instanceof CarbonInterface) {
                $toDate = $to->copy()->setTimezone('Europe/Zurich')->startOfDay();
            } else {
                $toDate = $this->convertToCarbon($to)->setTimezone('Europe/Zurich')->startOfDay();
            }
        } else {
            $toDate = null;
        }

        $filters = [
            'start_date' => $fromDate->format('Y-m-d'),
        ];

        if ($toDate !== null) {
            $filters['end_date'] = $toDate->format('Y-m-d');
        }

        $apiData = $this->apiService->fetchAll($filters);
        return $this->convertApiDataToTimesheets($apiData);
    }

    /**
     * Get timesheets by frame UUIDs.
     * Note: Frame UUIDs are local-only, so this cannot be queried from the API.
     * Returns empty array as frame UUIDs are not stored in Zebra.
     *
     * @param array<string> $frameUuids Array of frame UUIDs to search for
     * @return array<TimesheetInterface>
     */
    public function getByFrameUuids(array $frameUuids): array
    {
        // Frame UUIDs are local-only and not stored in Zebra API
        return [];
    }

    /**
     * Create a new timesheet in Zebra via API.
     *
     * @param TimesheetInterface $timesheet The timesheet to create
     * @return TimesheetInterface The updated timesheet with zebraId and remote data
     */
    public function create(TimesheetInterface $timesheet): TimesheetInterface
    {
        $apiData = $this->convertTimesheetToApiData($timesheet);
        $response = $this->apiService->create($apiData);

        // After successful creation, check if response contains the created timesheet
        // POST response structure: { "success": true, "data": { "timesheet": { ... } } }
        if (isset($response['data']['timesheet']) && is_array($response['data']['timesheet'])) {
            try {
                return TimesheetFactory::fromApiResponse(
                    $response['data']['timesheet'],
                    $this->activityRepo,
                    $this->userRepo
                );
            } catch (TrackException $e) {
                // Fall through to fallback method
            }
        }

        // Also check for direct ID in data (for backward compatibility)
        if (isset($response['data']['id']) && is_int($response['data']['id'])) {
            return $this->getByZebraId($response['data']['id']);
        }

        // If response contains full timesheet data at data level, convert it
        if (isset($response['data']) && is_array($response['data']) && isset($response['data']['id'])) {
            try {
                return TimesheetFactory::fromApiResponse(
                    $response['data'],
                    $this->activityRepo,
                    $this->userRepo
                );
            } catch (TrackException $e) {
                // Fall through to fallback method
            }
        }

        // Fallback: fetch by date range and match by content
        // This is not ideal but necessary if API doesn't return the ID
        $dateRange = $this->getByDateRange($timesheet->date, $timesheet->date);
        foreach ($dateRange as $fetchedTimesheet) {
            // Match by project, activity, and description (time excluded as it may have been edited/rounded)
            if (
                $fetchedTimesheet->getProjectId() === $timesheet->getProjectId()
                && $fetchedTimesheet->activity->entityKey->id === $timesheet->activity->entityKey->id
                && $fetchedTimesheet->description === $timesheet->description
            ) {
                return $fetchedTimesheet;
            }
        }

        // If we can't find it, throw an exception as this indicates a problem
        throw new TrackException(
            'Failed to retrieve created timesheet from Zebra API. ' .
            'The timesheet may have been created but could not be fetched.'
        );
    }

    /**
     * Update an existing timesheet in Zebra via API.
     *
     * @param TimesheetInterface $timesheet The timesheet to update (must have zebraId)
     * @param callable $confirmationCallback Callback to confirm the update.
     *                                       Must return true to proceed, false to cancel.
     *                                       Receives the timesheet as parameter.
     * @return TimesheetInterface|null The updated timesheet with remote data, or null if cancelled
     */
    public function update(TimesheetInterface $timesheet, callable $confirmationCallback): ?TimesheetInterface
    {
        if ($timesheet->zebraId === null) {
            throw new InvalidArgumentException('Cannot update timesheet without zebraId');
        }

        // Request confirmation (required)
        $confirmed = $confirmationCallback($timesheet);
        if (!$confirmed) {
            return null; // User cancelled
        }

        $apiData = $this->convertTimesheetToApiData($timesheet);
        $this->apiService->update($timesheet->zebraId, $apiData);

        // After successful update, fetch the updated timesheet
        return $this->getByZebraId($timesheet->zebraId);
    }

    /**
     * Delete a timesheet from Zebra via API.
     *
     * @param int $zebraId The Zebra timesheet ID
     * @param callable $confirmationCallback Callback to confirm the deletion.
     *                                       Must return true to proceed, false to cancel.
     *                                       Receives the zebraId as parameter.
     * @return bool True if deleted, false if cancelled
     */
    public function delete(int $zebraId, callable $confirmationCallback): bool
    {
        // Request confirmation (required)
        $confirmed = $confirmationCallback($zebraId);
        if (!$confirmed) {
            return false; // User cancelled
        }

        $this->apiService->delete($zebraId);
        return true;
    }

    /**
     * Convert API data array to Timesheet objects.
     *
     * @param array<int, array<string, mixed>> $apiData
     * @return array<TimesheetInterface>
     */
    private function convertApiDataToTimesheets(array $apiData): array
    {
        $timesheets = [];

        foreach ($apiData as $timesheetData) {
            try {
                $timesheets[] = TimesheetFactory::fromApiResponse(
                    $timesheetData,
                    $this->activityRepo,
                    $this->userRepo
                );
            } catch (TrackException $e) {
                // Skip timesheets that cannot be converted
                continue;
            }
        }

        return $timesheets;
    }

    /**
     * Convert Timesheet to API request data format.
     *
     * @param TimesheetInterface $timesheet
     * @return array<string, mixed>
     */
    private function convertTimesheetToApiData(TimesheetInterface $timesheet): array
    {
        $activityId = is_int($timesheet->activity->entityKey->id)
            ? $timesheet->activity->entityKey->id
            : null;

        if ($activityId === null) {
            throw new InvalidArgumentException('Timesheet activity must have integer ID for API');
        }

        $data = [
            'project_id' => $timesheet->getProjectId(),
            'activity_id' => $activityId,
            'description' => $timesheet->description,
            'time' => $timesheet->time,
            'date' => $timesheet->date->setTimezone('Europe/Zurich')->format('Y-m-d'),
        ];

        if ($timesheet->clientDescription !== null) {
            $data['client_description'] = $timesheet->clientDescription;
        }

        if ($timesheet->role !== null) {
            $data['role_id'] = $timesheet->role->id;
        }

        return $data;
    }

    /**
     * Convert a time value to a CarbonInterface instance.
     *
     * @param CarbonInterface|int|string $value
     * @return CarbonInterface
     */
    private function convertToCarbon(CarbonInterface|int|string $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value);
        }

        // Parse string in local timezone, then convert to UTC
        static $timezoneFormatter = null;
        if ($timezoneFormatter === null) {
            $timezoneFormatter = new TimezoneFormatter();
        }
        return $timezoneFormatter->parseLocalToUtc($value);
    }

    /**
     * Fetch raw API data for a timesheet by Zebra ID.
     * This is useful for getting raw date fields that need timezone conversion.
     *
     * @param int $zebraId The Zebra API ID
     * @return array<string, mixed> Raw API response data
     * @throws TrackException
     */
    public function fetchRawApiData(int $zebraId): array
    {
        return $this->apiService->fetchById($zebraId);
    }
}
