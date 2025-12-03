<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

/**
 * Repository for storing and retrieving local timesheets.
 * Uses JSON file storage to persist timesheets.
 */
class LocalTimesheetRepository implements LocalTimesheetRepositoryInterface
{
    private const string DEFAULT_STORAGE_FILENAME = 'timesheets.json';
    private readonly string $storageFilename;

    /**
     * @param TimesheetFileStorageFactoryInterface $storageFactory
     * @param string $storageFilename The storage filename (defaults to 'timesheets.json')
     */
    public function __construct(
        private readonly TimesheetFileStorageFactoryInterface $storageFactory,
        string $storageFilename = self::DEFAULT_STORAGE_FILENAME
    ) {
        $this->storageFilename = $storageFilename;
    }

    /**
     * Save a timesheet to storage.
     *
     * @param TimesheetInterface $timesheet
     * @return void
     * @throws InvalidArgumentException If timesheet has a zebraId that already exists for a different UUID
     */
    public function save(TimesheetInterface $timesheet): void
    {
        $timesheets = $this->loadFromStorage();

        // Check for duplicate zebraId (if timesheet has one)
        $this->validateZebraIdUniqueness($timesheet, $timesheets);

        // Store timesheet by UUID (will overwrite if UUID already exists)
        $timesheets[$timesheet->uuid] = $timesheet->toArray();

        $this->saveToStorage($timesheets);
    }

    /**
     * Get all timesheets from storage.
     *
     * @return array<TimesheetInterface>
     */
    public function all(): array
    {
        $timesheetsData = $this->loadFromStorage();
        $timesheets = [];

        foreach ($timesheetsData as $timesheetData) {
            try {
                $timesheets[] = TimesheetFactory::fromArray($timesheetData);
            } catch (\Exception $e) {
                // Skip timesheets that cannot be deserialized
                continue;
            }
        }

        return $timesheets;
    }

    /**
     * Get a timesheet by its UUID.
     *
     * @param string $uuid
     * @return TimesheetInterface|null
     */
    public function get(string $uuid): ?TimesheetInterface
    {
        $timesheetsData = $this->loadFromStorage();

        if (!isset($timesheetsData[$uuid])) {
            return null;
        }

        try {
            return TimesheetFactory::fromArray($timesheetsData[$uuid]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get timesheet by Zebra ID.
     *
     * @param int $zebraId The Zebra API ID
     * @return TimesheetInterface|null
     */
    public function getByZebraId(int $zebraId): ?TimesheetInterface
    {
        $allTimesheets = $this->all();
        return array_find($allTimesheets, fn($timesheet) => $timesheet->zebraId === $zebraId);
    }

    /**
     * Get timesheets within a date range.
     * Filters timesheets by their date.
     *
     * @param CarbonInterface|int|string $from The start date (inclusive)
     * @param CarbonInterface|int|string|null $to The end date (inclusive, optional)
     * @return array<TimesheetInterface>
     */
    public function getByDateRange(CarbonInterface|int|string $from, CarbonInterface|int|string|null $to = null): array
    {
        // Convert input dates to Europe/Zurich timezone for comparison (matches API timezone)
        $fromDate = $this->convertDateToCarbon($from)->setTimezone('Europe/Zurich')->startOfDay();
        $toDate = $to !== null ? $this->convertDateToCarbon($to)->setTimezone('Europe/Zurich')->startOfDay() : null;

        $allTimesheets = $this->all();
        $filteredTimesheets = [];

        foreach ($allTimesheets as $timesheet) {
            // Compare dates in Europe/Zurich timezone (timesheet dates are stored in Europe/Zurich)
            $timesheetDate = $timesheet->date->setTimezone('Europe/Zurich')->startOfDay();

            // Timesheet must be at or after the "from" date
            if ($timesheetDate->lt($fromDate)) {
                continue;
            }

            // If "to" is provided, timesheet must be at or before the "to" date
            if ($toDate !== null && $timesheetDate->gt($toDate)) {
                continue;
            }

            $filteredTimesheets[] = $timesheet;
        }

        return $filteredTimesheets;
    }

    /**
     * Get timesheets by frame UUIDs.
     * Returns all timesheets that reference any of the provided frame UUIDs.
     *
     * @param array<string> $frameUuids Array of frame UUIDs to search for
     * @return array<TimesheetInterface>
     */
    public function getByFrameUuids(array $frameUuids): array
    {
        $allTimesheets = $this->all();
        $filteredTimesheets = [];

        foreach ($allTimesheets as $timesheet) {
            // Check if any of the timesheet's frame UUIDs match any of the provided frame UUIDs
            foreach ($timesheet->frameUuids as $timesheetFrameUuid) {
                if (in_array($timesheetFrameUuid, $frameUuids, true)) {
                    $filteredTimesheets[] = $timesheet;
                    break; // Only add once per timesheet
                }
            }
        }

        return $filteredTimesheets;
    }

    /**
     * Get all timesheets for a specific date.
     *
     * @param CarbonInterface $date The date to filter by
     * @return array<TimesheetInterface>
     */
    public function getByDate(CarbonInterface $date): array
    {
        return $this->getByDateRange($date, $date);
    }

    /**
     * Get all timesheets without a zebraId (not yet pushed to Zebra).
     *
     * @return array<TimesheetInterface>
     */
    public function getUnsynced(): array
    {
        $allTimesheets = $this->all();
        $unsyncedTimesheets = [];

        foreach ($allTimesheets as $timesheet) {
            if ($timesheet->zebraId === null) {
                $unsyncedTimesheets[] = $timesheet;
            }
        }

        return $unsyncedTimesheets;
    }

    /**
     * Update an existing timesheet.
     *
     * @param TimesheetInterface $timesheet
     * @return void
     * @throws InvalidArgumentException If timesheet has a zebraId that already exists for a different UUID
     */
    public function update(TimesheetInterface $timesheet): void
    {
        $timesheets = $this->loadFromStorage();

        // Check if timesheet exists
        if (!isset($timesheets[$timesheet->uuid])) {
            throw new InvalidArgumentException(
                "Cannot update timesheet: timesheet with UUID '{$timesheet->uuid}' does not exist."
            );
        }

        // Check for duplicate zebraId (if timesheet has one)
        $this->validateZebraIdUniqueness($timesheet, $timesheets);

        // Update the timesheet
        $timesheets[$timesheet->uuid] = $timesheet->toArray();

        $this->saveToStorage($timesheets);
    }

    /**
     * Remove a timesheet by UUID.
     *
     * @param string $uuid
     * @return void
     */
    public function remove(string $uuid): void
    {
        $timesheets = $this->loadFromStorage();

        // Check if timesheet exists
        if (!isset($timesheets[$uuid])) {
            throw new InvalidArgumentException(
                "Cannot remove timesheet: timesheet with UUID '{$uuid}' does not exist."
            );
        }

        // Remove the timesheet
        unset($timesheets[$uuid]);

        $this->saveToStorage($timesheets);
    }

    /**
     * Convert a date value to a CarbonInterface instance in Europe/Zurich timezone.
     * Date-only strings (Y-m-d format) are parsed as Europe/Zurich dates (API timezone).
     * Other strings are parsed in the local/system timezone, then converted to Europe/Zurich.
     *
     * @param CarbonInterface|int|string $value
     * @return CarbonInterface
     */
    private function convertDateToCarbon(CarbonInterface|int|string $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->setTimezone('Europe/Zurich');
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value, 'Europe/Zurich');
        }

        // Check if it's a date-only string (Y-m-d format) - these are stored as Europe/Zurich dates
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            // Parse as Europe/Zurich date to match API timezone
            return Carbon::parse($value, 'Europe/Zurich')->startOfDay();
        }

        // Parse other strings in local timezone, then convert to Europe/Zurich
        // This ensures strings without timezone info are interpreted in user's local timezone
        static $timezoneFormatter = null;
        if ($timezoneFormatter === null) {
            $timezoneFormatter = new TimezoneFormatter();
        }
        $localParsed = $timezoneFormatter->parseLocalToUtc($value);
        return $localParsed->setTimezone('Europe/Zurich');
    }


    /**
     * Load timesheets from storage file.
     * Returns timesheets as an associative array keyed by UUID.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadFromStorage(): array
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $data = $storage->read();

        if (empty($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Save timesheets to storage file.
     *
     * @param array<string, array<string, mixed>> $timesheets
     * @return void
     */
    private function saveToStorage(array $timesheets): void
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $storage->write($timesheets);
    }

    /**
     * Validate that a timesheet's zebraId is unique (doesn't exist for another UUID).
     *
     * @param TimesheetInterface $timesheet The timesheet to validate
     * @param array<string, array<string, mixed>> $existingTimesheets Existing timesheets data
     * @return void
     * @throws InvalidArgumentException If zebraId already exists for a different UUID
     */
    private function validateZebraIdUniqueness(
        TimesheetInterface $timesheet,
        array $existingTimesheets
    ): void {
        // Only validate if timesheet has a zebraId
        if ($timesheet->zebraId === null) {
            return;
        }

        foreach ($existingTimesheets as $existingUuid => $existingData) {
            // Skip the same UUID (updating existing timesheet is allowed)
            if ($existingUuid === $timesheet->uuid) {
                continue;
            }

            // Check if another timesheet already has this zebraId
            if (isset($existingData['zebraId']) && $existingData['zebraId'] === $timesheet->zebraId) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Cannot save timesheet: zebraId %d already exists for timesheet UUID '%s'. " .
                        "Each zebraId must be unique. This usually indicates a duplicate timesheet was created. " .
                        "Please remove the duplicate timesheet before saving.",
                        $timesheet->zebraId,
                        $existingUuid
                    )
                );
            }
        }
    }
}
