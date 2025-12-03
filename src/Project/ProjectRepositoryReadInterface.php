<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use Tcrawf\Zebra\EntityKey\EntityKeyInterface;

/**
 * Base interface for project repository read operations.
 * Contains common read methods shared by both local and Zebra project repositories.
 */
interface ProjectRepositoryReadInterface
{
    /**
     * Get all projects filtered by status.
     *
     * @param array<ProjectStatus> $statuses Optional array of project statuses to filter by.
     *                                        If empty array is provided, returns all projects (no filtering).
     *                                        If not provided, defaults to [ProjectStatus::Active].
     * @return array<ProjectInterface>
     */
    public function all(array $statuses = [ProjectStatus::Active]): array;

    /**
     * Get a project by its entity key.
     * The repository will route to the appropriate source based on the key's source.
     *
     * @param EntityKeyInterface $entityKey The project's entity key
     * @return ProjectInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ProjectInterface;

    /**
     * Get all projects with a name like the provided one.
     *
     * @param string $name
     * @return array<ProjectInterface>
     */
    public function getByNameLike(string $name): array;

    /**
     * Get a project by activity entity key.
     * The repository will route to the appropriate source based on the key's source.
     *
     * @param EntityKeyInterface $activityEntityKey The activity's entity key
     * @return ProjectInterface|null
     */
    public function getByActivityId(EntityKeyInterface $activityEntityKey): ?ProjectInterface;

    /**
     * Get a project by activity alias.
     *
     * @param string $alias
     * @return ProjectInterface|null
     */
    public function getByActivityAlias(string $alias): ?ProjectInterface;

    /**
     * Get all activity aliases from all projects.
     *
     * @return array<string> Array of all activity aliases (excluding null values)
     */
    public function getAllAliases(): array;
}
