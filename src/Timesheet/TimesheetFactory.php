<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Exception\TrackException;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Factory for creating Timesheet entities with date conversion logic.
 * Separates date handling concerns from the Timesheet entity.
 */
class TimesheetFactory
{
    public static function create(
        ActivityInterface $activity,
        string $description,
        string|null $clientDescription,
        float $time,
        CarbonInterface|int|string $date,
        RoleInterface|null $role,
        bool $individualAction,
        array $frameUuids,
        int|null $zebraId = null,
        CarbonInterface|int|string|null $updatedAt = null,
        UuidInterface|null $uuid = null,
        bool $doNotSync = false
    ): Timesheet {
        // Generate UUID if not provided
        $timesheetUuid = $uuid ?? Uuid::random();

        return new Timesheet(
            $timesheetUuid,
            $activity,
            $description,
            $clientDescription,
            $time,
            $date,
            $role,
            $individualAction,
            $frameUuids,
            $zebraId,
            $updatedAt,
            $doNotSync
        );
    }

    public static function fromArray(array $data): Timesheet
    {
        if (!isset($data['uuid'])) {
            throw new TrackException("Invalid array format: 'uuid' key is required");
        }

        $uuidHex = $data['uuid'];
        $uuid = Uuid::fromHex($uuidHex);

        if (!isset($data['activity'])) {
            throw new TrackException("Invalid array format: 'activity' key is required");
        }

        if (!isset($data['description'])) {
            throw new TrackException("Invalid array format: 'description' key is required");
        }

        if (!isset($data['time'])) {
            throw new TrackException("Invalid array format: 'time' key is required");
        }

        if (!isset($data['date'])) {
            throw new TrackException("Invalid array format: 'date' key is required");
        }

        if (!isset($data['frameUuids'])) {
            throw new TrackException("Invalid array format: 'frameUuids' key is required");
        }

        if (!is_array($data['frameUuids'])) {
            throw new TrackException("Invalid array format: 'frameUuids' must be an array");
        }

        // Reconstruct Activity from array if needed
        $activity = $data['activity'];
        if (is_array($activity)) {
            if (!isset($activity['key']) || !isset($activity['project'])) {
                throw new TrackException(
                    "Invalid array format: 'activity' must have 'key' and 'project' keys"
                );
            }

            $activityEntityKey = self::createEntityKeyFromArray($activity['key']);
            $projectEntityKey = self::createEntityKeyFromArray($activity['project']);

            $activity = new Activity(
                $activityEntityKey,
                $activity['name'],
                $activity['desc'] ?? '',
                $projectEntityKey,
                $activity['alias'] ?? null
            );
        }

        if (!($activity instanceof ActivityInterface)) {
            throw new TrackException(
                "Invalid array format: 'activity' must be an array or ActivityInterface"
            );
        }

        // Reconstruct Role from array if needed
        $role = $data['role'] ?? null;
        if (is_array($role)) {
            if (!isset($role['id'])) {
                throw new TrackException(
                    "Invalid array format: 'role' must have 'id' key"
                );
            }

            $role = new Role(
                $role['id'],
                $role['parentId'] ?? null,
                $role['name'] ?? '',
                $role['fullName'] ?? '',
                $role['type'] ?? '',
                $role['status'] ?? ''
            );
        } elseif ($role !== null && !($role instanceof RoleInterface)) {
            throw new TrackException(
                "Invalid array format: 'role' must be an array, null, or RoleInterface"
            );
        }

        // Handle doNotSync property (defaults to false for backward compatibility)
        $doNotSync = isset($data['doNotSync']) ? (bool) $data['doNotSync'] : false;

        return new Timesheet(
            $uuid,
            $activity,
            $data['description'],
            $data['clientDescription'] ?? null,
            $data['time'],
            $data['date'],
            $role,
            $data['individualAction'] ?? false,
            $data['frameUuids'],
            $data['zebraId'] ?? null,
            $data['updatedAt'] ?? null,
            $doNotSync
        );
    }

    /**
     * Create a Timesheet from Zebra API response data.
     * Converts API response array to Timesheet object, looking up Activity and Role.
     *
     * @param array<string, mixed> $apiData API response data
     * @param ActivityRepositoryInterface $activityRepo Repository for looking up activities
     * @param UserRepositoryInterface $userRepo Repository for looking up roles
     * @param UuidInterface|null $uuid Optional UUID (generated if not provided)
     * @return Timesheet
     * @throws TrackException If required data is missing or lookups fail
     */
    public static function fromApiResponse(
        array $apiData,
        ActivityRepositoryInterface $activityRepo,
        UserRepositoryInterface $userRepo,
        UuidInterface|null $uuid = null
    ): Timesheet {
        // Generate UUID if not provided
        $timesheetUuid = $uuid ?? Uuid::random();

        // Normalize field names (API uses different names in different contexts)
        // Handle both 'occupation_id' (from GET) and 'occupid' (from POST response)
        $occupationId = $apiData['occupation_id'] ?? $apiData['occupid'] ?? null;
        if ($occupationId === null) {
            throw new TrackException("Invalid API data: 'occupation_id' or 'occupid' is required");
        }
        // Convert to int if string
        $occupationId = is_string($occupationId) ? (int) $occupationId : $occupationId;
        if (!is_int($occupationId)) {
            throw new TrackException("Invalid API data: 'occupation_id' or 'occupid' must be an integer");
        }

        if (!isset($apiData['date']) || !is_string($apiData['date'])) {
            throw new TrackException("Invalid API data: 'date' is required and must be a string");
        }

        if (!isset($apiData['time']) || !is_numeric($apiData['time'])) {
            throw new TrackException("Invalid API data: 'time' is required and must be numeric");
        }

        if (!isset($apiData['description']) || !is_string($apiData['description'])) {
            throw new TrackException("Invalid API data: 'description' is required and must be a string");
        }

        // Look up Activity by occupation_id
        $activityEntityKey = EntityKey::zebra($occupationId);
        $activity = $activityRepo->get($activityEntityKey);
        if ($activity === null) {
            throw new TrackException(
                "Activity not found for occupation_id: {$occupationId}"
            );
        }

        // Look up Role by role_id if present
        $role = null;
        if (isset($apiData['role_id'])) {
            $roleId = is_string($apiData['role_id']) ? (int) $apiData['role_id'] : $apiData['role_id'];
            if (is_int($roleId)) {
                $userRoles = $userRepo->getCurrentUserRoles();
                foreach ($userRoles as $userRole) {
                    if ($userRole->id === $roleId) {
                        $role = $userRole;
                        break;
                    }
                }
                // If role not found in user roles, create a minimal Role object
                if ($role === null) {
                    $role = new Role($roleId);
                }
            }
        }

        // Parse lu_date (last updated date) - format: "YYYY-MM-DD HH:MM:SS" in Europe/Zurich timezone
        // Convert to UTC timestamp for storage
        $updatedAt = null;
        if (isset($apiData['lu_date']) && is_string($apiData['lu_date'])) {
            try {
                // API returns lu_date in Europe/Zurich timezone
                $updatedAt = Carbon::parse($apiData['lu_date'], 'Europe/Zurich')->utc()->timestamp;
            } catch (\Exception $e) {
                // If parsing fails, use current time as timestamp
                $updatedAt = Carbon::now()->utc()->timestamp;
            }
        } elseif (isset($apiData['modified']) && is_string($apiData['modified'])) {
            // Also check 'modified' field (API uses this in some responses)
            try {
                $updatedAt = Carbon::parse($apiData['modified'], 'Europe/Zurich')->utc()->timestamp;
            } catch (\Exception $e) {
                $updatedAt = Carbon::now()->utc()->timestamp;
            }
        }

        // Parse date - API returns dates in Europe/Zurich timezone (Y-m-d format)
        $date = $apiData['date'];

        // Get other fields
        $description = $apiData['description'];
        $clientDescription = isset($apiData['client_description'])
            ? (string) $apiData['client_description']
            : null;
        $time = (float) $apiData['time'];
        $individualAction = isset($apiData['individual_action']) && $apiData['individual_action'] === true;
        $zebraId = isset($apiData['id']) && is_int($apiData['id']) ? $apiData['id'] : null;

        // Frame UUIDs are not in API response, use empty array
        $frameUuids = [];

        return new Timesheet(
            $timesheetUuid,
            $activity,
            $description,
            $clientDescription,
            $time,
            $date,
            $role,
            $individualAction,
            $frameUuids,
            $zebraId,
            $updatedAt,
            false // doNotSync defaults to false for API responses
        );
    }

    /**
     * Create an EntityKey from array data.
     *
     * @param array<string, mixed> $entityKeyData
     * @return EntityKey
     */
    private static function createEntityKeyFromArray(array $entityKeyData): EntityKey
    {
        if (!isset($entityKeyData['source']) || !isset($entityKeyData['id'])) {
            throw new TrackException(
                "Invalid array format: 'entityKey' must have 'source' and 'id' keys"
            );
        }

        $source = EntitySource::from($entityKeyData['source']);
        return new EntityKey($source, $entityKeyData['id']);
    }
}
