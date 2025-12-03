<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use InvalidArgumentException;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;

/**
 * Interface for local project repository.
 * Defines the contract for CRUD operations on local projects.
 */
interface LocalProjectRepositoryInterface extends ProjectRepositoryReadInterface
{
    /**
     * Create a new local project.
     *
     * @param string $name Project name
     * @param string $description Project description
     * @param int $status Project status (0 = inactive, 1 = active)
     * @return ProjectInterface The created project
     */
    public function create(string $name, string $description, int $status = 1): ProjectInterface;

    /**
     * Update an existing local project.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @param string|null $name Optional new name
     * @param string|null $description Optional new description
     * @param int|null $status Optional new status
     * @return ProjectInterface The updated project
     */
    public function update(
        EntityKeyInterface $entityKey,
        ?string $name = null,
        ?string $description = null,
        ?int $status = null
    ): ProjectInterface;

    /**
     * Delete a local project.
     * Throws an exception if the project has activities, unless force is true.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @param bool $force If true, delete even if project has activities (cascades to activities)
     * @return void
     * @throws InvalidArgumentException If project has activities and force is false
     */
    public function delete(EntityKeyInterface $entityKey, bool $force = false): void;

    /**
     * Check if a project has any activities.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return bool True if project has activities, false otherwise
     */
    public function hasActivities(EntityKeyInterface $entityKey): bool;

    /**
     * Get all activities in a project.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return array<ActivityInterface> Array of activities in the project
     */
    public function getActivities(EntityKeyInterface $entityKey): array;

    /**
     * Force delete a local project, cascading deletion to all activities and frames.
     * This will delete all activities in the project and all frames referencing those activities.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @param callable $deleteActivityCallback Callback to delete activities (receives ActivityInterface)
     * @param callable $deleteFrameCallback Callback to delete frames (receives FrameInterface)
     * @return void
     */
    public function forceDelete(
        EntityKeyInterface $entityKey,
        callable $deleteActivityCallback,
        callable $deleteFrameCallback
    ): void;

    /**
     * Update a project's activities array.
     * This is used internally by LocalActivityRepository to update activities.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @param array<ActivityInterface> $activities The updated activities array
     * @return ProjectInterface The updated project
     */
    public function updateActivities(EntityKeyInterface $entityKey, array $activities): ProjectInterface;
}
