<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

/**
 * Service for migrating timesheets from old format (denormalized activity data)
 * to new format (normalized activity key only).
 */
class TimesheetMigrationService
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
     * Check if any timesheets use the old format (denormalized activity data or role object).
     *
     * @return bool True if migration is needed, false otherwise
     */
    public function needsMigration(): bool
    {
        $timesheetsData = $this->loadTimesheetsFromStorage();

        // Check all timesheets
        foreach ($timesheetsData as $timesheetData) {
            if ($this->isOldFormat($timesheetData)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Migrate all timesheets from old format to new format.
     * Rewrites storage file with normalized format.
     *
     * @return int Number of timesheets migrated
     */
    public function migrateTimesheets(): int
    {
        $timesheetsData = $this->loadTimesheetsFromStorage();
        $migratedCount = 0;

        // Migrate all timesheets
        $migratedTimesheets = [];
        foreach ($timesheetsData as $uuid => $timesheetData) {
            if ($this->isOldFormat($timesheetData)) {
                $migratedTimesheets[$uuid] = $this->migrateTimesheet($timesheetData);
                $migratedCount++;
            } else {
                $migratedTimesheets[$uuid] = $timesheetData;
            }
        }

        // Save migrated timesheets back to storage
        $this->saveTimesheetsToStorage($migratedTimesheets);

        return $migratedCount;
    }

    /**
     * Migrate a single timesheet from old format to new format.
     *
     * @param array<string, mixed> $timesheetData
     * @return array<string, mixed> Migrated timesheet data
     */
    public function migrateTimesheet(array $timesheetData): array
    {
        if (!$this->isOldFormat($timesheetData)) {
            return $timesheetData; // Already in new format
        }

        $activityData = $timesheetData['activity'];
        if (!is_array($activityData)) {
            return $timesheetData; // Invalid format, return as-is
        }

        // Extract activity key from old format
        if (!isset($activityData['key'])) {
            // Very old format without key - not supported
            throw new \RuntimeException(
                'Cannot migrate timesheet: activity key not found. ' .
                'Very old format without activity.key is no longer supported.'
            );
        }

        // Create normalized format: only keep the activity key
        $migratedTimesheet = $timesheetData;
        $migratedTimesheet['activity'] = [
            'key' => $activityData['key'],
        ];

        // Remove projectId - it's derived from activity, no need to store it
        unset($migratedTimesheet['projectId']);

        // Normalize role: convert from old format (role object) to new format (roleId)
        if (isset($migratedTimesheet['role'])) {
            if (is_array($migratedTimesheet['role']) && isset($migratedTimesheet['role']['id'])) {
                // Old format: role is an array/object with 'id'
                $migratedTimesheet['roleId'] = (int) $migratedTimesheet['role']['id'];
                unset($migratedTimesheet['role']);
            } elseif ($migratedTimesheet['role'] === null) {
                // Null role: remove 'role' key, ensure 'roleId' is null
                $migratedTimesheet['roleId'] = null;
                unset($migratedTimesheet['role']);
            } else {
                // Invalid format: remove 'role' key, set 'roleId' to null
                $migratedTimesheet['roleId'] = null;
                unset($migratedTimesheet['role']);
            }
        } elseif (!isset($migratedTimesheet['roleId'])) {
            // No role data at all: set roleId to null (for individual timesheets)
            $migratedTimesheet['roleId'] = null;
        }

        return $migratedTimesheet;
    }

    /**
     * Check if timesheet data uses old format (has denormalized activity data, role object, or projectId).
     *
     * @param array<string, mixed> $timesheetData
     * @return bool True if old format, false if new format
     */
    private function isOldFormat(array $timesheetData): bool
    {
        // Check for projectId (old format - project is derived from activity)
        if (isset($timesheetData['projectId'])) {
            return true;
        }

        // Check for old activity format
        if (isset($timesheetData['activity']) && is_array($timesheetData['activity'])) {
            $activityData = $timesheetData['activity'];

            // Old format has: name, desc, project, alias, roleRequired keys (in addition to key)
            // New format has: only key
            $hasOldActivityFormat = isset($activityData['name'])
                || isset($activityData['desc'])
                || isset($activityData['project'])
                || isset($activityData['alias'])
                || isset($activityData['roleRequired']);

            if ($hasOldActivityFormat) {
                return true;
            }
        }

        // Check for old role format
        // Old format has: 'role' as object/array with id, name, etc.
        // New format has: 'roleId' as integer or null
        if (isset($timesheetData['role']) && is_array($timesheetData['role'])) {
            // Old format: role is an object/array
            return true;
        }
        if (isset($timesheetData['role']) && !isset($timesheetData['roleId'])) {
            // Has 'role' but not 'roleId' - old format
            return true;
        }
        // If has both 'role' and 'roleId', it's still old format (should only have 'roleId')
        if (isset($timesheetData['role']) && isset($timesheetData['roleId'])) {
            return true;
        }

        // New format: has 'roleId' (or neither 'role' nor 'roleId' for null roles)
        return false;
    }

    /**
     * Load timesheets from storage file.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadTimesheetsFromStorage(): array
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
    private function saveTimesheetsToStorage(array $timesheets): void
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $storage->write($timesheets);
    }
}
