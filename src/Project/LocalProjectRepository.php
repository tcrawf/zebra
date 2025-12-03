<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use InvalidArgumentException;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Repository for storing and retrieving local projects.
 * Uses JSON file storage in ~/.zebra/local-projects.json.
 */
class LocalProjectRepository implements LocalProjectRepositoryInterface
{
    private const string DEFAULT_FILENAME = 'local-projects.json';
    private readonly string $filename;

    /** @var array<string, ProjectInterface> Static cache of projects indexed by UUID string */
    private static ?array $projectCache = null;

    /**
     * @param string $filename The storage filename (defaults to 'local-projects.json')
     */
    public function __construct(string $filename = self::DEFAULT_FILENAME)
    {
        $this->filename = $filename;
    }

    /**
     * Get all local projects filtered by status.
     *
     * @param array<ProjectStatus> $statuses Optional array of project statuses to filter by.
     *                                        If empty array is provided, returns all projects (no filtering).
     *                                        If not provided, defaults to [ProjectStatus::Active].
     * @return array<ProjectInterface>
     */
    public function all(array $statuses = [ProjectStatus::Active]): array
    {
        $projects = $this->loadFromStorage();

        // If empty array provided, return all projects (no filtering)
        if (empty($statuses)) {
            return array_values($projects);
        }

        // Filter projects by status
        $statusValues = array_map(static fn(ProjectStatus $status) => $status->value, $statuses);
        return array_values(array_filter(
            $projects,
            static fn(ProjectInterface $project) => in_array($project->status, $statusValues, true)
        ));
    }

    /**
     * Get a local project by its entity key.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return ProjectInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ProjectInterface
    {
        if ($entityKey->source !== EntitySource::Local) {
            return null;
        }

        if (!($entityKey->id instanceof UuidInterface)) {
            return null;
        }

        $uuidString = $entityKey->id->getHex();
        $projects = $this->loadFromStorage();
        return $projects[$uuidString] ?? null;
    }

    /**
     * Get all local projects with a name like the provided one.
     * Prioritizes "starts with" matches, falls back to "contains" if no "starts with" matches exist.
     *
     * @param string $name
     * @return array<ProjectInterface>
     */
    public function getByNameLike(string $name): array
    {
        $projects = $this->all();
        $nameLower = strtolower($name);
        $startsWithMatches = [];
        $containsMatches = [];

        foreach ($projects as $project) {
            $projectNameLower = trim(strtolower($project->name));

            // Prioritize "starts with" matches
            if (str_starts_with($projectNameLower, $nameLower)) {
                $startsWithMatches[] = $project;
            } elseif (str_contains($projectNameLower, $nameLower)) {
                // Fallback to "contains" matches only if not already in starts with
                $containsMatches[] = $project;
            }
        }

        // Sort both arrays by name
        usort($startsWithMatches, static fn($a, $b) => strcasecmp($a->name, $b->name));
        usort($containsMatches, static fn($a, $b) => strcasecmp($a->name, $b->name));

        // Return startsWithMatches first, then containsMatches
        return array_merge($startsWithMatches, $containsMatches);
    }

    /**
     * Get a local project by activity entity key.
     *
     * @param EntityKeyInterface $activityEntityKey The activity's entity key
     * @return ProjectInterface|null
     */
    public function getByActivityId(EntityKeyInterface $activityEntityKey): ?ProjectInterface
    {
        if ($activityEntityKey->source !== EntitySource::Local) {
            return null;
        }

        if (!($activityEntityKey->id instanceof UuidInterface)) {
            return null;
        }

        $activityUuidString = $activityEntityKey->id->getHex();
        $projects = $this->all();

        return array_find(
            $projects,
            static fn($project) => array_any(
                $project->activities,
                static fn($activity) => $activity->entityKey->source === EntitySource::Local
                    && $activity->entityKey->id instanceof UuidInterface
                    && $activity->entityKey->id->getHex() === $activityUuidString
            )
        );
    }

    /**
     * Get a local project by activity alias.
     *
     * @param string $alias
     * @return ProjectInterface|null
     */
    public function getByActivityAlias(string $alias): ?ProjectInterface
    {
        $projects = $this->all();
        return array_find(
            $projects,
            static fn($project) => array_any(
                $project->activities,
                static fn($activity) => $activity->alias === $alias
                    && $activity->entityKey->source === EntitySource::Local
            )
        );
    }

    /**
     * Get all activity aliases from all local projects.
     *
     * @return array<string> Array of all activity aliases (excluding null values)
     */
    public function getAllAliases(): array
    {
        $projects = $this->all();
        $aliases = [];

        foreach ($projects as $project) {
            foreach ($project->activities as $activity) {
                if ($activity->alias !== null && $activity->entityKey->source === EntitySource::Local) {
                    $aliases[] = $activity->alias;
                }
            }
        }

        return $aliases;
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
        $uuid = Uuid::random();
        $projectEntityKey = EntityKey::local($uuid);

        $project = new Project(
            $projectEntityKey,
            $name,
            $description,
            $status,
            []
        );

        $this->saveProject($project);
        return $project;
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
        if ($entityKey->source !== EntitySource::Local) {
            throw new InvalidArgumentException('Entity key must be from Local source');
        }

        $project = $this->get($entityKey);
        if ($project === null) {
            $uuidString = $entityKey->id instanceof UuidInterface
                ? $entityKey->id->getHex()
                : (string) $entityKey->id;
            throw new InvalidArgumentException("Project with UUID {$uuidString} not found");
        }

        $newName = $name ?? $project->name;
        $newDescription = $description ?? $project->description;
        $newStatus = $status ?? $project->status;

        $updatedProject = new Project(
            $project->entityKey,
            $newName,
            $newDescription,
            $newStatus,
            $project->activities
        );

        $this->saveProject($updatedProject);
        return $updatedProject;
    }

    /**
     * Delete a local project.
     * Throws an exception if the project has activities, unless force is true.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @param bool $force If true, delete even if project has activities (cascades to activities)
     * @return void
     * @throws InvalidArgumentException If project has activities and force is false
     */
    public function delete(EntityKeyInterface $entityKey, bool $force = false): void
    {
        if ($entityKey->source !== EntitySource::Local) {
            throw new InvalidArgumentException('Entity key must be from Local source');
        }

        if (!($entityKey->id instanceof UuidInterface)) {
            throw new InvalidArgumentException('Entity key ID must be a UUID for Local source');
        }

        $project = $this->get($entityKey);
        if ($project === null) {
            $uuidString = $entityKey->id->getHex();
            throw new InvalidArgumentException("Project with UUID {$uuidString} not found");
        }

        // Check if project has activities
        if (!$force && $this->hasActivities($entityKey)) {
            $uuidString = $entityKey->id->getHex();
            throw new InvalidArgumentException(
                "Cannot delete project with UUID {$uuidString}: project has activities. " .
                "Use force delete to cascade deletion."
            );
        }

        $projects = $this->loadFromStorage();
        $uuidString = $entityKey->id->getHex();

        unset($projects[$uuidString]);
        $this->saveAllProjects($projects);
    }

    /**
     * Check if a project has any activities.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return bool True if project has activities, false otherwise
     */
    public function hasActivities(EntityKeyInterface $entityKey): bool
    {
        $project = $this->get($entityKey);
        if ($project === null) {
            return false;
        }

        return !empty($project->activities);
    }

    /**
     * Get all activities in a project.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return array<ActivityInterface> Array of activities in the project
     */
    public function getActivities(EntityKeyInterface $entityKey): array
    {
        $project = $this->get($entityKey);
        if ($project === null) {
            return [];
        }

        return $project->activities;
    }

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
    ): void {
        $project = $this->get($entityKey);
        if ($project === null) {
            $uuidString = $entityKey->id instanceof UuidInterface
                ? $entityKey->id->getHex()
                : (string) $entityKey->id;
            throw new InvalidArgumentException("Project with UUID {$uuidString} not found");
        }

        // Delete all frames referencing activities in this project
        // Then delete all activities
        foreach ($project->activities as $activity) {
            // Delete frames referencing this activity (callback handles finding frames)
            $deleteFrameCallback($activity);
            // Delete the activity
            $deleteActivityCallback($activity);
        }

        // Finally delete the project itself
        $this->delete($entityKey, true);
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
        if ($entityKey->source !== EntitySource::Local) {
            throw new InvalidArgumentException('Entity key must be from Local source');
        }

        $project = $this->get($entityKey);
        if ($project === null) {
            $uuidString = $entityKey->id instanceof UuidInterface
                ? $entityKey->id->getHex()
                : (string) $entityKey->id;
            throw new InvalidArgumentException("Project with UUID {$uuidString} not found");
        }

        $updatedProject = new Project(
            $project->entityKey,
            $project->name,
            $project->description,
            $project->status,
            $activities
        );

        $this->saveProject($updatedProject);
        return $updatedProject;
    }

    /**
     * Save a single project to storage.
     *
     * @param ProjectInterface $project
     * @return void
     */
    private function saveProject(ProjectInterface $project): void
    {
        $projects = $this->loadFromStorage();
        $uuidString = $project->entityKey->id instanceof UuidInterface
            ? $project->entityKey->id->getHex()
            : (string) $project->entityKey->id;

        $projects[$uuidString] = $project;
        $this->saveAllProjects($projects);
    }

    /**
     * Save all projects to storage.
     *
     * @param array<string, ProjectInterface> $projects
     * @return void
     */
    private function saveAllProjects(array $projects): void
    {
        $storage = new LocalProjectFileStorage($this->filename);
        $data = [];

        foreach ($projects as $project) {
            $uuidString = $project->entityKey->id instanceof UuidInterface
                ? $project->entityKey->id->getHex()
                : (string) $project->entityKey->id;

            $projectData = [
                'id' => $uuidString,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'activities' => [],
            ];

            foreach ($project->activities as $activity) {
                $activityUuidString = $activity->entityKey->id instanceof UuidInterface
                    ? $activity->entityKey->id->getHex()
                    : (string) $activity->entityKey->id;

                $projectData['activities'][] = [
                    'id' => $activityUuidString,
                    'name' => $activity->name,
                    'description' => $activity->description,
                    'alias' => $activity->alias,
                ];
            }

            $data[$uuidString] = $projectData;
        }

        $storage->write($data);
        self::$projectCache = $projects;
    }

    /**
     * Load projects from storage.
     *
     * @return array<string, ProjectInterface>
     */
    private function loadFromStorage(): array
    {
        if (self::$projectCache !== null) {
            return self::$projectCache;
        }

        $storage = new LocalProjectFileStorage($this->filename);
        $data = $storage->read();

        if (empty($data)) {
            self::$projectCache = [];
            return [];
        }

        $projects = [];
        foreach ($data as $projectData) {
            $project = $this->createProjectFromArray($projectData);
            $uuidString = $project->entityKey->id instanceof UuidInterface
                ? $project->entityKey->id->getHex()
                : (string) $project->entityKey->id;
            $projects[$uuidString] = $project;
        }

        self::$projectCache = $projects;
        return $projects;
    }

    /**
     * Create a Project entity from array data.
     *
     * @param array<string, mixed> $data
     * @return ProjectInterface
     */
    private function createProjectFromArray(array $data): ProjectInterface
    {
        $uuid = Uuid::fromHex($data['id']);
        $projectEntityKey = EntityKey::local($uuid);

        $activities = [];
        if (isset($data['activities']) && is_array($data['activities'])) {
            foreach ($data['activities'] as $activityData) {
                $activities[] = $this->createActivityFromArray($activityData, $projectEntityKey);
            }
        }

        return new Project(
            $projectEntityKey,
            $data['name'],
            $data['description'] ?? '',
            $data['status'],
            $activities
        );
    }

    /**
     * Create an Activity entity from array data.
     *
     * @param array<string, mixed> $activityData
     * @param EntityKey $projectEntityKey
     * @return ActivityInterface
     */
    private function createActivityFromArray(array $activityData, EntityKey $projectEntityKey): ActivityInterface
    {
        $uuid = Uuid::fromHex($activityData['id']);
        $activityEntityKey = EntityKey::local($uuid);

        return new Activity(
            $activityEntityKey,
            $activityData['name'],
            $activityData['description'] ?? '',
            $projectEntityKey,
            $activityData['alias'] ?? null
        );
    }
}
