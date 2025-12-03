<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Exception\TrackException;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
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
        Activity $activity,
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
            $activity,
            $isIndividual,
            $role,
            $description,
            $updatedAt
        );
    }

    public static function fromArray(array $data): Frame
    {
        if (!isset($data['uuid'])) {
            throw new TrackException("Invalid array format: 'uuid' key is required");
        }

        if (!isset($data['isIndividual'])) {
            throw new TrackException("Invalid array format: 'isIndividual' key is required");
        }

        $uuidHex = $data['uuid'];
        $uuid = Uuid::fromHex($uuidHex);
        $isIndividual = (bool) $data['isIndividual'];

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

        // Reconstruct Role from array if needed
        // Role is always present in storage (null for individual frames)
        $role = null;
        if (isset($data['role'])) {
            if (is_array($data['role'])) {
                $role = new Role(
                    $data['role']['id'],
                    null,
                    $data['role']['name'] ?? '',
                    '',
                    '',
                    ''
                );
            } elseif ($data['role'] instanceof RoleInterface) {
                $role = $data['role'];
            } else {
                throw new TrackException(
                    "Invalid array format: 'role' must be null, an array, or RoleInterface"
                );
            }
        }
        // If role is not set in data, it defaults to null (for backward compatibility with old data)

        return new Frame(
            $uuid,
            $data['start'] ?? null,
            $data['stop'] ?? null,
            $activity,
            $isIndividual,
            $role,
            $data['desc'] ?? '',
            $data['updatedAt'] ?? null
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
    public static function withStopTime(FrameInterface $frame, CarbonInterface|int|string $stopTime): Frame
    {
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

        return self::fromArray($data);
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
