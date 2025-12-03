<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

use InvalidArgumentException;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Interface for local activity repository.
 * Defines the contract for CRUD operations on local activities.
 */
interface LocalActivityRepositoryInterface extends ActivityRepositoryReadInterface
{
    /**
     * Create a new local activity.
     *
     * @param string $name Activity name
     * @param string $description Activity description
     * @param EntityKeyInterface $projectEntityKey The parent project's entity key
     * @param string|null $alias Optional activity alias
     * @return ActivityInterface The created activity
     */
    public function create(
        string $name,
        string $description,
        EntityKeyInterface $projectEntityKey,
        ?string $alias = null
    ): ActivityInterface;

    /**
     * Update an existing local activity.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param string|null $name Optional new name
     * @param string|null $description Optional new description
     * @param string|null $alias Optional new alias
     * @return ActivityInterface The updated activity
     */
    public function update(
        UuidInterface|string $uuid,
        ?string $name = null,
        ?string $description = null,
        ?string $alias = null
    ): ActivityInterface;

    /**
     * Delete a local activity.
     * Throws an exception if the activity has frames referencing it, unless force is true.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param bool $force If true, delete even if activity has frames (cascades to frames)
     * @return void
     * @throws InvalidArgumentException If activity has frames and force is false
     */
    public function delete(UuidInterface|string $uuid, bool $force = false): void;

    /**
     * Check if an activity has any frames referencing it.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @return bool True if activity has frames, false otherwise
     */
    public function hasFrames(UuidInterface|string $uuid): bool;

    /**
     * Get all frames referencing an activity.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @return array<\Tcrawf\Zebra\Frame\FrameInterface> Array of frames referencing the activity
     */
    public function getFrames(UuidInterface|string $uuid): array;

    /**
     * Force delete a local activity, cascading deletion to all frames referencing it.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param callable $deleteFrameCallback Callback to delete frames (receives FrameInterface)
     * @return void
     */
    public function forceDelete(UuidInterface|string $uuid, callable $deleteFrameCallback): void;
}
