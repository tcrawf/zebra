<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Exception\TrackException;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Factory for creating Frame entities with date conversion logic.
 * Separates date handling concerns from the Frame entity.
 */
class FrameFactory
{
    public static function create(
        CarbonInterface|int|string $startTime,
        CarbonInterface|int|string|null $stopTime,
        ActivityInterface $activity,
        bool $isIndividual,
        RoleInterface|null $role,
        string $description = '',
        CarbonInterface|int|string|null $updatedAt = null,
        UuidInterface|null $uuid = null
    ): Frame {
        // Generate UUID if not provided
        $frameUuid = $uuid ?? Uuid::random();

        return new Frame(
            $frameUuid,
            $startTime,
            $stopTime,
            $activity, // Frame constructor will extract activityKey from Activity
            $isIndividual,
            $role, // Frame constructor will extract roleId from Role
            $description,
            $updatedAt
        );
    }

    public static function fromArray(
        array $data,
        ?ActivityRepositoryInterface $activityRepository = null,
        ?UserRepositoryInterface $userRepository = null
    ): Frame {
        if (!isset($data['uuid'])) {
            throw new TrackException("Invalid array format: 'uuid' key is required");
        }

        if (!isset($data['isIndividual'])) {
            throw new TrackException("Invalid array format: 'isIndividual' key is required");
        }

        $uuidHex = $data['uuid'];
        $uuid = Uuid::fromHex($uuidHex);
        $isIndividual = (bool) $data['isIndividual'];

        // Handle activity: only support normalized format (only key)
        $activityData = $data['activity'];
        $activityKey = null;

        if (is_array($activityData)) {
            // Normalized format: only has 'key'
            if (!isset($activityData['key'])) {
                throw new TrackException(
                    "Invalid array format: 'activity' must have 'key' field"
                );
            }
            $activityKey = self::createEntityKeyFromArray($activityData['key']);
        } elseif ($activityData instanceof ActivityInterface) {
            // Already an Activity object (shouldn't happen in storage, but handle it)
            $activityKey = $activityData->entityKey;
        } else {
            throw new TrackException(
                "Invalid array format: 'activity' must be an array with 'key' field or ActivityInterface"
            );
        }

        if ($activityKey === null) {
            throw new TrackException("Failed to extract activity key from frame data");
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
            } elseif ($data['role'] === null) {
                $roleId = null;
            } else {
                throw new TrackException(
                    "Invalid array format: 'role' must be null, an array with 'id', or RoleInterface"
                );
            }
        }
        // If role is not set in data, it defaults to null (for backward compatibility with old data)

        return new Frame(
            $uuid,
            $data['start'] ?? null,
            $data['stop'] ?? null,
            $activityKey, // Pass EntityKeyInterface, Frame will load Activity via repository
            $isIndividual,
            $roleId, // Pass roleId (int|null), Frame will load Role via repository
            $data['desc'] ?? '',
            $data['updatedAt'] ?? null,
            $activityRepository,
            $userRepository
        );
    }

    /**
     * Create a new Frame instance with a stop time, preserving all other properties.
     * Updates the updatedAt timestamp to the current time.
     *
     * @param FrameInterface $frame The frame to clone
     * @param CarbonInterface|int|string $stopTime The stop time to set
     * @return Frame A new Frame instance with the stop time set
     */
    public static function withStopTime(
        FrameInterface $frame,
        CarbonInterface|int|string $stopTime,
        ?ActivityRepositoryInterface $activityRepository = null,
        ?UserRepositoryInterface $userRepository = null
    ): Frame {
        $data = $frame->toArray();
        // Replace stopTime and update updatedAt
        if (is_int($stopTime)) {
            $data['stop'] = $stopTime;
        } elseif ($stopTime instanceof CarbonInterface) {
            $data['stop'] = $stopTime->timestamp;
        } else {
            // Parse string in local timezone, then convert to UTC and get timestamp
            static $timezoneFormatter = null;
            if ($timezoneFormatter === null) {
                $timezoneFormatter = new TimezoneFormatter();
            }
            $data['stop'] = $timezoneFormatter->parseLocalToUtc($stopTime)->timestamp;
        }
        $data['updatedAt'] = Carbon::now()->timestamp;

        return self::fromArray($data, $activityRepository, $userRepository);
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
