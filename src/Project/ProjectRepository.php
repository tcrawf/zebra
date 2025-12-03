<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;

/**
 * Proxy repository for storing and retrieving projects from both local and Zebra sources.
 * Delegates to LocalProjectRepository and ZebraProjectRepository and merges results.
 */
class ProjectRepository implements ProjectRepositoryInterface
{
    /**
     * @param LocalProjectRepositoryInterface $localRepository
     * @param ZebraProjectRepositoryInterface $zebraRepository
     */
    public function __construct(
        private readonly LocalProjectRepositoryInterface $localRepository,
        private readonly ZebraProjectRepositoryInterface $zebraRepository
    ) {
    }

    /**
     * Get all projects from both local and Zebra sources filtered by status.
     * Merges results from both repositories.
     *
     * @param array<ProjectStatus> $statuses Optional array of project statuses to filter by.
     *                                        If empty array is provided, returns all projects (no filtering).
     *                                        If not provided, defaults to [ProjectStatus::Active].
     * @return array<ProjectInterface>
     */
    public function all(array $statuses = [ProjectStatus::Active]): array
    {
        $localProjects = $this->localRepository->all($statuses);
        $zebraProjects = $this->zebraRepository->all($statuses);

        return array_merge($localProjects, $zebraProjects);
    }

    /**
     * Get a project by its entity key.
     * Routes to the appropriate repository based on the entity key's source.
     *
     * @param EntityKeyInterface $entityKey
     * @return ProjectInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ProjectInterface
    {
        return match ($entityKey->source) {
            EntitySource::Local => $this->localRepository->get($entityKey),
            EntitySource::Zebra => $this->zebraRepository->get($entityKey),
        };
    }

    /**
     * Get all projects with a name like the provided one.
     * Searches both local and Zebra repositories and merges results.
     * Prioritizes "starts with" matches, falls back to "contains" if no "starts with" matches exist.
     *
     * @param string $name
     * @return array<ProjectInterface>
     */
    public function getByNameLike(string $name): array
    {
        $localProjects = $this->localRepository->getByNameLike($name);
        $zebraProjects = $this->zebraRepository->getByNameLike($name);

        // Merge and sort: prioritize "starts with" matches
        $nameLower = strtolower($name);
        $startsWithMatches = [];
        $containsMatches = [];

        foreach (array_merge($localProjects, $zebraProjects) as $project) {
            $projectNameLower = trim(strtolower($project->name));

            if (str_starts_with($projectNameLower, $nameLower)) {
                $startsWithMatches[] = $project;
            } elseif (str_contains($projectNameLower, $nameLower)) {
                $containsMatches[] = $project;
            }
        }

        if (!empty($startsWithMatches)) {
            usort($startsWithMatches, static fn($a, $b) => strcasecmp($a->name, $b->name));
            return $startsWithMatches;
        }

        usort($containsMatches, static fn($a, $b) => strcasecmp($a->name, $b->name));
        return $containsMatches;
    }

    /**
     * Get a project by activity entity key.
     * Routes to the appropriate repository based on the entity key's source.
     *
     * @param EntityKeyInterface $activityEntityKey The activity's entity key
     * @return ProjectInterface|null
     */
    public function getByActivityId(EntityKeyInterface $activityEntityKey): ?ProjectInterface
    {
        return match ($activityEntityKey->source) {
            EntitySource::Local => $this->localRepository->getByActivityId($activityEntityKey),
            EntitySource::Zebra => $this->zebraRepository->getByActivityId($activityEntityKey),
        };
    }

    /**
     * Get a project by activity alias.
     * Searches both local and Zebra repositories. Returns the first match found.
     *
     * @param string $alias
     * @return ProjectInterface|null
     */
    public function getByActivityAlias(string $alias): ?ProjectInterface
    {
        // Check local first, then Zebra
        $project = $this->localRepository->getByActivityAlias($alias);
        if ($project !== null) {
            return $project;
        }

        return $this->zebraRepository->getByActivityAlias($alias);
    }

    /**
     * Update project data from the API.
     * Only affects Zebra repository.
     */
    public function updateFromApi(): void
    {
        $this->zebraRepository->updateFromApi();
    }

    /**
     * Refresh project data from pre-fetched data.
     * Only affects Zebra repository.
     *
     * @param array<int, array<string, mixed>> $data
     */
    public function refreshFromData(array $data): void
    {
        $this->zebraRepository->refreshFromData($data);
    }

    /**
     * Get all activity aliases from all projects in both local and Zebra sources.
     *
     * @return array<string> Array of all activity aliases (excluding null values)
     */
    public function getAllAliases(): array
    {
        $localAliases = $this->localRepository->getAllAliases();
        $zebraAliases = $this->zebraRepository->getAllAliases();

        return array_merge($localAliases, $zebraAliases);
    }

    /**
     * Create a new local project.
     *
     * @param string $name Project name
     * @param string $description Project description
     * @param int $status Project status (0 = inactive, 1 = active)
     * @return ProjectInterface The created project
     */
    public function create(string $name, string $description, int $status = 1): ProjectInterface
    {
        return $this->localRepository->create($name, $description, $status);
    }

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
    ): ProjectInterface {
        return $this->localRepository->update($entityKey, $name, $description, $status);
    }

    /**
     * Delete a local project.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @param bool $force If true, delete even if project has activities (cascades to activities)
     * @return void
     */
    public function delete(EntityKeyInterface $entityKey, bool $force = false): void
    {
        $this->localRepository->delete($entityKey, $force);
    }

    /**
     * Check if a project has any activities.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return bool True if project has activities, false otherwise
     */
    public function hasActivities(EntityKeyInterface $entityKey): bool
    {
        return $this->localRepository->hasActivities($entityKey);
    }

    /**
     * Get all activities in a project.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return array<ActivityInterface> Array of activities in the project
     */
    public function getActivities(EntityKeyInterface $entityKey): array
    {
        return $this->localRepository->getActivities($entityKey);
    }

    /**
     * Force delete a local project, cascading deletion to all activities and frames.
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
    ): void {
        $this->localRepository->forceDelete($entityKey, $deleteActivityCallback, $deleteFrameCallback);
    }

    /**
     * Update a project's activities array.
     * This is used internally by LocalActivityRepository to update activities.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @param array<ActivityInterface> $activities The updated activities array
     * @return ProjectInterface The updated project
     */
    public function updateActivities(EntityKeyInterface $entityKey, array $activities): ProjectInterface
    {
        return $this->localRepository->updateActivities($entityKey, $activities);
    }
}
