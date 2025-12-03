<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Cache\CacheFileStorageFactoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;

/**
 * Repository for storing and retrieving Zebra projects.
 * Uses JSON file storage and fetches from API when needed.
 */
class ZebraProjectRepository implements ZebraProjectRepositoryInterface
{
    private const string DEFAULT_CACHE_FILENAME = 'projects.json';
    private readonly string $cacheFilename;

    /** @var array<string, array<int, ProjectInterface>> Static cache of projects indexed by cache filename, then by project ID */
    private static array $projectCacheByFilename = [];

    /**
     * @param ProjectApiServiceInterface $apiService
     * @param CacheFileStorageFactoryInterface $cacheStorageFactory
     * @param string $cacheFilename The cache filename (defaults to 'projects.json')
     */
    public function __construct(
        private readonly ProjectApiServiceInterface $apiService,
        private readonly CacheFileStorageFactoryInterface $cacheStorageFactory,
        string $cacheFilename = self::DEFAULT_CACHE_FILENAME
    ) {
        $this->cacheFilename = $cacheFilename;
    }

    /**
     * Get all Zebra projects filtered by status.
     * Returns cached data if available, otherwise fetches from API and caches the result.
     * The API service always fetches all projects regardless of status, and the cache stores all.
     * Filtering is applied based on the provided statuses.
     *
     * @param array<ProjectStatus> $statuses Optional array of project statuses to filter by.
     *                                        If empty array is provided, returns all projects (no filtering).
     *                                        If not provided, defaults to [ProjectStatus::Active].
     * @return array<ProjectInterface>
     */
    public function all(array $statuses = [ProjectStatus::Active]): array
    {
        // Load from cache (populates static cache if needed)
        $projects = $this->loadFromCache();
        if (empty($projects)) {
            // Fetch all projects (API always fetches all, regardless of status)
            $data = $this->apiService->fetchAll();
            $this->saveToCache($data);
            // After saving, populate static cache directly from the data we just saved
            $projects = $this->populateStaticCacheFromData($data);
        }

        // If empty array provided, return all projects (no filtering)
        if (empty($statuses)) {
            return $projects;
        }

        // Filter projects by status
        $statusValues = array_map(static fn(ProjectStatus $status) => $status->value, $statuses);
        return array_values(array_filter(
            $projects,
            static fn(ProjectInterface $project) => in_array($project->status, $statusValues, true)
        ));
    }

    /**
     * Get a Zebra project by its entity key.
     * Uses static cache populated by loadFromCache().
     *
     * @param EntityKeyInterface $entityKey
     * @return ProjectInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ProjectInterface
    {
        if ($entityKey->source !== EntitySource::Zebra) {
            return null;
        }

        if (!is_int($entityKey->id)) {
            return null;
        }

        // Load from cache if not already loaded (populates static cache)
        if (!$this->hasStaticCache()) {
            $this->loadFromCache();
        }

        return $this->getFromStaticCache($entityKey->id);
    }

    /**
     * Get all Zebra projects with a name like the provided one.
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

        // If we have "starts with" matches, return those (sorted by name)
        if (!empty($startsWithMatches)) {
            usort($startsWithMatches, static fn($a, $b) => strcasecmp($a->name, $b->name));
            return $startsWithMatches;
        }

        // Otherwise, return "contains" matches (sorted by name)
        usort($containsMatches, static fn($a, $b) => strcasecmp($a->name, $b->name));
        return $containsMatches;
    }

    /**
     * Get a Zebra project by activity entity key.
     *
     * @param EntityKeyInterface $activityEntityKey The activity's entity key
     * @return ProjectInterface|null
     */
    public function getByActivityId(EntityKeyInterface $activityEntityKey): ?ProjectInterface
    {
        if ($activityEntityKey->source !== EntitySource::Zebra) {
            return null;
        }

        if (!is_int($activityEntityKey->id)) {
            return null;
        }

        $projects = $this->all();
        return array_find(
            $projects,
            static fn($project) => array_any(
                $project->activities,
                static fn($activity) => $activity->entityKey->source === EntitySource::Zebra
                    && $activity->entityKey->id === $activityEntityKey->id
            )
        );
    }

    /**
     * Get a Zebra project by activity alias.
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
                    && $activity->entityKey->source === EntitySource::Zebra
            )
        );
    }

    /**
     * Update project data from the API.
     * Always fetches all projects regardless of status.
     * Updates the static cache with the new data.
     */
    public function updateFromApi(): void
    {
        $data = $this->apiService->fetchAll();
        $this->saveToCache($data);
        // Populate static cache directly from the data we just fetched
        $this->populateStaticCacheFromData($data);
    }

    /**
     * Refresh project data from pre-fetched data.
     * Writes the provided data to cache and updates static cache without fetching from API.
     *
     * @param array<int, array<string, mixed>> $data
     */
    public function refreshFromData(array $data): void
    {
        $this->saveToCache($data);
        // Populate static cache directly from the data we just provided
        $this->populateStaticCacheFromData($data);
    }

    /**
     * Get all activity aliases from all Zebra projects.
     *
     * @return array<string> Array of all activity aliases (excluding null values)
     */
    public function getAllAliases(): array
    {
        $projects = $this->all();
        $aliases = [];

        foreach ($projects as $project) {
            foreach ($project->activities as $activity) {
                if ($activity->alias !== null && $activity->entityKey->source === EntitySource::Zebra) {
                    $aliases[] = $activity->alias;
                }
            }
        }

        return $aliases;
    }

    /**
     * Load projects from cached JSON file and populate static cache.
     *
     * @return array<ProjectInterface>
     */
    private function loadFromCache(): array
    {
        // If static cache is already populated for this filename, return it
        if ($this->hasStaticCache()) {
            return array_values($this->getStaticCache());
        }

        // Read from file storage
        $cacheStorage = $this->cacheStorageFactory->create($this->cacheFilename);
        $data = $cacheStorage->read();

        // If file storage is empty, set static cache to empty array and return
        if (empty($data)) {
            $this->setStaticCache([]);
            return [];
        }

        // Load from file and populate static cache
        $projects = array_map(function ($projectData) {
            return $this->createProjectFromArray($projectData);
        }, $data);

        // Populate static cache indexed by ID (for Zebra source, use int ID)
        $cacheById = [];
        foreach ($projects as $project) {
            if ($project->entityKey->source === EntitySource::Zebra && is_int($project->entityKey->id)) {
                $cacheById[$project->entityKey->id] = $project;
            }
        }
        $this->setStaticCache($cacheById);

        return $projects;
    }

    /**
     * Save projects to cached JSON file.
     *
     * @param array<int, array<string, mixed>> $data
     */
    private function saveToCache(array $data): void
    {
        // Convert integer keys to string keys for write() method
        $jsonData = [];
        foreach ($data as $key => $projectData) {
            $jsonData[(string) $key] = $projectData;
        }

        $cacheStorage = $this->cacheStorageFactory->create($this->cacheFilename);
        $cacheStorage->write($jsonData);
    }

    /**
     * Populate static cache from API data and return projects array.
     *
     * @param array<int, array<string, mixed>> $data
     * @return array<ProjectInterface>
     */
    private function populateStaticCacheFromData(array $data): array
    {
        // Load from data and populate static cache
        $projects = array_map(function ($projectData) {
            return $this->createProjectFromArray($projectData);
        }, $data);

        // Populate static cache indexed by ID (for Zebra source, use int ID)
        $cacheById = [];
        foreach ($projects as $project) {
            if ($project->entityKey->source === EntitySource::Zebra && is_int($project->entityKey->id)) {
                $cacheById[$project->entityKey->id] = $project;
            }
        }
        $this->setStaticCache($cacheById);

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
        // Create EntityKey with Zebra source for project
        $projectEntityKey = EntityKey::zebra($data['id']);

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
        // Create EntityKey with Zebra source for activity
        $activityEntityKey = EntityKey::zebra($activityData['id']);

        return new Activity(
            $activityEntityKey,
            $activityData['name'],
            $activityData['description'] ?? '',
            $projectEntityKey,
            $activityData['alias'] ?? null
        );
    }

    /**
     * Check if static cache exists for this cache filename.
     *
     * @return bool
     */
    private function hasStaticCache(): bool
    {
        return isset(self::$projectCacheByFilename[$this->cacheFilename]);
    }

    /**
     * Get the static cache for this cache filename.
     *
     * @return array<int, ProjectInterface>
     */
    private function getStaticCache(): array
    {
        return self::$projectCacheByFilename[$this->cacheFilename] ?? [];
    }

    /**
     * Set the static cache for this cache filename.
     *
     * @param array<int, ProjectInterface> $cache
     */
    private function setStaticCache(array $cache): void
    {
        self::$projectCacheByFilename[$this->cacheFilename] = $cache;
    }

    /**
     * Get a project from static cache by ID.
     *
     * @param int $id
     * @return ProjectInterface|null
     */
    private function getFromStaticCache(int $id): ?ProjectInterface
    {
        return $this->getStaticCache()[$id] ?? null;
    }
}
