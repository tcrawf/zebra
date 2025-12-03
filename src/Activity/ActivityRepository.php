<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Proxy repository for retrieving activities from both local and Zebra sources.
 * Delegates to LocalActivityRepository and ZebraActivityRepository and merges results.
 */
class ActivityRepository implements ActivityRepositoryInterface
{
    /**
     * @param LocalActivityRepositoryInterface $localRepository
     * @param ZebraActivityRepositoryInterface $zebraRepository
     */
    public function __construct(
        private readonly LocalActivityRepositoryInterface $localRepository,
        private readonly ZebraActivityRepositoryInterface $zebraRepository
    ) {
    }

    /**
     * Get all activities from both local and Zebra projects.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     *
     * @param bool $activeOnly If true, only load active projects. Defaults to true.
     * @return array<ActivityInterface> Array of activities from matching projects
     */
    public function all(bool $activeOnly = true): array
    {
        $localActivities = $this->localRepository->all($activeOnly);
        $zebraActivities = $this->zebraRepository->all($activeOnly);

        return array_merge($localActivities, $zebraActivities);
    }

    /**
     * Get an activity by its entity key.
     * Routes to the appropriate repository based on the entity key's source.
     * Returns activities regardless of project status.
     *
     * @param EntityKeyInterface $entityKey
     * @return ActivityInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ActivityInterface
    {
        return match ($entityKey->source) {
            EntitySource::Local => $this->localRepository->get($entityKey),
            EntitySource::Zebra => $this->zebraRepository->get($entityKey),
        };
    }

    /**
     * Get an activity by its alias.
     * Searches both local and Zebra repositories. Returns the first match found.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     *
     * @param string $alias The activity alias to search for
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return ActivityInterface|null
     */
    public function getByAlias(string $alias, bool $activeOnly = true): ?ActivityInterface
    {
        // Check local first, then Zebra
        $activity = $this->localRepository->getByAlias($alias, $activeOnly);
        if ($activity !== null) {
            return $activity;
        }

        return $this->zebraRepository->getByAlias($alias, $activeOnly);
    }

    /**
     * Search for activities by name or alias (case-insensitive contains).
     * Searches both local and Zebra repositories and merges results.
     * If activeOnly is true (default), only searches in active projects.
     * If activeOnly is false, searches in all projects.
     *
     * @param string $search The search string to match against activity names or aliases
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByNameOrAlias(string $search, bool $activeOnly = true): array
    {
        $localMatches = $this->localRepository->searchByNameOrAlias($search, $activeOnly);
        $zebraMatches = $this->zebraRepository->searchByNameOrAlias($search, $activeOnly);

        return array_merge($localMatches, $zebraMatches);
    }

    /**
     * Search for activities by alias only (case-insensitive contains).
     * Searches both local and Zebra repositories and merges results.
     * Only returns activities from active projects (status === 1).
     *
     * @param string $search The search string to match against activity aliases
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByAlias(string $search): array
    {
        $localMatches = $this->localRepository->searchByAlias($search);
        $zebraMatches = $this->zebraRepository->searchByAlias($search);

        return array_merge($localMatches, $zebraMatches);
    }

    /**
     * Create a new local activity.
     * Delegates to LocalActivityRepository.
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
    ): ActivityInterface {
        return $this->localRepository->create($name, $description, $projectEntityKey, $alias);
    }

    /**
     * Update an existing local activity.
     * Delegates to LocalActivityRepository.
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
    ): ActivityInterface {
        return $this->localRepository->update($uuid, $name, $description, $alias);
    }

    /**
     * Delete a local activity.
     * Delegates to LocalActivityRepository.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param bool $force If true, delete even if activity has frames (cascades to frames)
     * @return void
     */
    public function delete(UuidInterface|string $uuid, bool $force = false): void
    {
        $this->localRepository->delete($uuid, $force);
    }

    /**
     * Check if an activity has any frames referencing it.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @return bool True if activity has frames, false otherwise
     */
    public function hasFrames(UuidInterface|string $uuid): bool
    {
        return $this->localRepository->hasFrames($uuid);
    }

    /**
     * Get all frames referencing an activity.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @return array<\Tcrawf\Zebra\Frame\FrameInterface> Array of frames referencing the activity
     */
    public function getFrames(UuidInterface|string $uuid): array
    {
        return $this->localRepository->getFrames($uuid);
    }

    /**
     * Force delete a local activity, cascading deletion to all frames referencing it.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param callable $deleteFrameCallback Callback to delete frames (receives FrameInterface)
     * @return void
     */
    public function forceDelete(UuidInterface|string $uuid, callable $deleteFrameCallback): void
    {
        $this->localRepository->forceDelete($uuid, $deleteFrameCallback);
    }
}
