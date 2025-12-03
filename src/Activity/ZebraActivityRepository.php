<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\ProjectStatus;
use Tcrawf\Zebra\Project\ZebraProjectRepositoryInterface;

/**
 * Repository for retrieving activities from Zebra projects.
 * Uses the Zebra project repository to find activities within projects.
 */
class ZebraActivityRepository implements ZebraActivityRepositoryInterface
{
    /**
     * @param ZebraProjectRepositoryInterface $projectRepository
     */
    public function __construct(
        private readonly ZebraProjectRepositoryInterface $projectRepository
    ) {
    }

    /**
     * Get all activities from Zebra projects.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     * Assumes there are no active activities on inactive projects.
     *
     * @param bool $activeOnly If true, only load active projects. Defaults to true.
     * @return array<ActivityInterface> Array of activities from matching projects
     */
    public function all(bool $activeOnly = true): array
    {
        // Load projects based on activeOnly flag
        if ($activeOnly) {
            $projects = $this->projectRepository->all([ProjectStatus::Active]);
        } else {
            $projects = $this->projectRepository->all([]);
        }

        $activities = [];

        foreach ($projects as $project) {
            foreach ($project->activities as $activity) {
                // Only include Zebra activities
                if ($activity->entityKey->source === EntitySource::Zebra) {
                    $activities[] = $activity;
                }
            }
        }

        return $activities;
    }

    /**
     * Get a Zebra activity by its entity key.
     * Returns activities regardless of project status.
     *
     * @param EntityKeyInterface $entityKey
     * @return ActivityInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ActivityInterface
    {
        if ($entityKey->source !== EntitySource::Zebra) {
            return null;
        }

        if (!is_int($entityKey->id)) {
            return null;
        }

        $project = $this->projectRepository->getByActivityId($entityKey);
        if ($project === null) {
            return null;
        }

        return array_find(
            $project->activities,
            static fn($activity) => $activity->entityKey->source === EntitySource::Zebra
                && $activity->entityKey->id === $entityKey->id
        );
    }

    /**
     * Get a Zebra activity by its alias.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     *
     * @param string $alias The activity alias to search for
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return ActivityInterface|null
     */
    public function getByAlias(string $alias, bool $activeOnly = true): ?ActivityInterface
    {
        $project = $this->projectRepository->getByActivityAlias($alias);
        if ($project === null) {
            return null;
        }

        // Only return activity if activeOnly is true and project is active (status === 1)
        if ($activeOnly && $project->status !== 1) {
            return null;
        }

        $activity = array_find(
            $project->activities,
            static fn($activity) => $activity->alias === $alias
                && $activity->entityKey->source === EntitySource::Zebra
        );

        return $activity;
    }

    /**
     * Search for Zebra activities by name or alias (case-insensitive contains).
     * If activeOnly is true (default), only searches in active projects.
     * If activeOnly is false, searches in all projects.
     *
     * @param string $search The search string to match against activity names or aliases
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByNameOrAlias(string $search, bool $activeOnly = true): array
    {
        $activities = $this->all($activeOnly);
        $matches = [];
        $searchLower = strtolower($search);

        foreach ($activities as $activity) {
            $nameLower = strtolower($activity->name);
            $aliasLower = $activity->alias !== null ? strtolower($activity->alias) : '';

            // Match by name or alias (case-insensitive contains)
            if (str_contains($nameLower, $searchLower) || str_contains($aliasLower, $searchLower)) {
                $matches[] = $activity;
            }
        }

        return $matches;
    }

    /**
     * Search for Zebra activities by alias only (case-insensitive contains).
     * Only returns activities from active projects (status === 1).
     *
     * @param string $search The search string to match against activity aliases
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByAlias(string $search): array
    {
        $activities = $this->all(true);
        $matches = [];
        $searchLower = strtolower($search);

        foreach ($activities as $activity) {
            if ($activity->alias !== null) {
                $aliasLower = strtolower($activity->alias);
                // Match by alias only (case-insensitive contains)
                if (str_contains($aliasLower, $searchLower)) {
                    $matches[] = $activity;
                }
            }
        }

        return $matches;
    }
}
