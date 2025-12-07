<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Exception\TrackException;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Factory for creating Task entities with date conversion logic.
 * Separates date handling concerns from the Task entity.
 */
class TaskFactory
{
    public static function create(
        string $summary,
        CarbonInterface|int|string|null $createdAt = null,
        CarbonInterface|int|string|null $dueAt = null,
        CarbonInterface|int|string|null $completedAt = null,
        ActivityInterface|null $activity = null,
        array $issueTags = [],
        TaskStatus $status = TaskStatus::Open,
        string $completionNote = '',
        UuidInterface|null $uuid = null
    ): Task {
        // Generate UUID if not provided
        $taskUuid = $uuid ?? Uuid::random();

        // Use current time if createdAt is not provided
        $created = $createdAt ?? Carbon::now();

        return new Task(
            $taskUuid,
            $summary,
            $created,
            $dueAt,
            $completedAt,
            $activity,
            $issueTags,
            $status,
            $completionNote
        );
    }

    public static function fromArray(array $data): Task
    {
        if (!isset($data['uuid'])) {
            throw new TrackException("Invalid array format: 'uuid' key is required");
        }

        if (!isset($data['summary'])) {
            throw new TrackException("Invalid array format: 'summary' key is required");
        }

        $uuidHex = $data['uuid'];
        $uuid = Uuid::fromHex($uuidHex);

        // Reconstruct Activity from array if needed
        $activity = null;
        if (isset($data['activity']) && $data['activity'] !== null) {
            if (is_array($data['activity'])) {
                if (!isset($data['activity']['key']) || !isset($data['activity']['project'])) {
                    throw new TrackException(
                        "Invalid array format: 'activity' must have 'key' and 'project' keys"
                    );
                }

                $activityEntityKey = self::createEntityKeyFromArray($data['activity']['key']);
                $projectEntityKey = self::createEntityKeyFromArray($data['activity']['project']);

                $activity = new Activity(
                    $activityEntityKey,
                    $data['activity']['name'],
                    $data['activity']['desc'] ?? '',
                    $projectEntityKey,
                    $data['activity']['alias'] ?? null
                );
            } elseif ($data['activity'] instanceof ActivityInterface) {
                $activity = $data['activity'];
            } else {
                throw new TrackException(
                    "Invalid array format: 'activity' must be null, an array, or ActivityInterface"
                );
            }
        }

        // Parse status
        $status = TaskStatus::Open;
        if (isset($data['status'])) {
            $status = TaskStatus::from($data['status']);
        }

        return new Task(
            $uuid,
            $data['summary'],
            $data['createdAt'] ?? null,
            $data['dueAt'] ?? null,
            $data['completedAt'] ?? null,
            $activity,
            $data['issueTags'] ?? [],
            $status,
            $data['completionNote'] ?? ''
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
