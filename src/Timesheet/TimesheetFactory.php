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

    public static function fromArray(
        array $data,
        ?ActivityRepositoryInterface $activityRepository = null,
        ?UserRepositoryInterface $userRepository = null
    ): Timesheet {
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

        // Handle activity: support both old format (full activity data) and new format (only key)
        $activity = $data['activity'];
        if (is_array($activity)) {
            // Require activity key
            if (!isset($activity['key'])) {
                throw new TrackException(
                    "Invalid array format: 'activity' must have 'key' field"
                );
            }

            $activityEntityKey = self::createEntityKeyFromArray($activity['key']);

            // Load activity from repository if available
            if ($activityRepository !== null) {
                $loadedActivity = $activityRepository->get($activityEntityKey);
                if ($loadedActivity !== null) {
                    $activity = $loadedActivity;
                } else {
                    // Activity not found in repository - this shouldn't happen with normalized data
                    // But handle gracefully for migration period
                    throw new TrackException(
                        "Activity not found in repository for key: {$activityEntityKey->toString()}"
                    );
                }
            } else {
                // No repository available - try to reconstruct from old format data if present
                // This handles backward compatibility during migration
                if (isset($activity['project']) && isset($activity['name'])) {
                    // Old format: reconstruct activity from stored data
                    $projectEntityKey = self::createEntityKeyFromArray($activity['project']);
                    $activity = new Activity(
                        $activityEntityKey,
                        $activity['name'],
                        $activity['desc'] ?? '',
                        $projectEntityKey,
                        $activity['alias'] ?? null,
                        $activity['roleRequired'] ?? false
                    );
                } else {
                    throw new TrackException(
                        "Invalid array format: 'activity' must have 'key' field and " .
                        "ActivityRepositoryInterface must be provided, " .
                        "or old format with 'name' and 'project' fields"
                    );
                }
            }
        }

        if (!($activity instanceof ActivityInterface)) {
            throw new TrackException(
                "Invalid array format: 'activity' must be an array with 'key' field or ActivityInterface"
            );
        }

        // Handle role: support both old format (full role object) and new format (only roleId)
        $roleId = null;
        if (isset($data['roleId'])) {
            // New format: only roleId
            $roleId = is_int($data['roleId']) ? $data['roleId'] : null;
        } elseif (isset($data['role'])) {
            // Old format: full role object (for backward compatibility during migration)
            if (is_array($data['role']) && isset($data['role']['id'])) {
                $roleId = (int) $data['role']['id'];
            } elseif ($data['role'] instanceof RoleInterface) {
                $roleId = $data['role']->id;
            } else {
                throw new TrackException(
                    "Invalid array format: 'role' must be null, an array with 'id', or RoleInterface"
                );
            }
        }
        // If role is not set in data, it defaults to null (for backward compatibility with old data)

        // Handle doNotSync property (defaults to false for backward compatibility)
        $doNotSync = isset($data['doNotSync']) ? (bool) $data['doNotSync'] : false;

        return new Timesheet(
            $uuid,
            $activity,
            $data['description'],
            $data['clientDescription'] ?? null,
            $data['time'],
            $data['date'],
            $roleId, // Pass roleId (int|null), Timesheet will load Role via repository
            $data['individualAction'] ?? false,
            $data['frameUuids'],
            $data['zebraId'] ?? null,
            $data['updatedAt'] ?? null,
            $doNotSync,
            $userRepository
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
        ?UuidInterface $uuid = null
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
