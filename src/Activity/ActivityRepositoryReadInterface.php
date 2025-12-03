<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

use Tcrawf\Zebra\EntityKey\EntityKeyInterface;

/**
 * Base interface for activity repository read operations.
 * Contains common read methods shared by both local and Zebra activity repositories.
 */
interface ActivityRepositoryReadInterface
{
    /**
     * Get all activities from projects.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     *
     * @param bool $activeOnly If true, only load active projects. Defaults to true.
     * @return array<ActivityInterface> Array of activities from matching projects
     */
    public function all(bool $activeOnly = true): array;

    /**
     * Get an activity by its entity key.
     * The repository will route to the appropriate source based on the key's source.
     *
     * @param EntityKeyInterface $entityKey The activity's entity key
     * @return ActivityInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ActivityInterface;

    /**
     * Get an activity by its alias.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     *
     * @param string $alias The activity alias to search for
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return ActivityInterface|null
     */
    public function getByAlias(string $alias, bool $activeOnly = true): ?ActivityInterface;

    /**
     * Search for activities by name or alias (case-insensitive contains).
     * If activeOnly is true (default), only searches in active projects.
     * If activeOnly is false, searches in all projects.
     *
     * @param string $search The search string to match against activity names or aliases
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByNameOrAlias(string $search, bool $activeOnly = true): array;

    /**
     * Search for activities by alias only (case-insensitive contains).
     *
     * @param string $search The search string to match against activity aliases
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByAlias(string $search): array;
}
