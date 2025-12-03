<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

/**
 * Interface for project API service.
 * Defines the contract for fetching projects from an external API.
 */
interface ProjectApiServiceInterface
{
    /**
     * Fetch all projects from the API (both active and inactive).
     * Always fetches projects with status 0 (inactive) and status 1 (active).
     * The API request is formatted as: ?statuses[]=0&statuses[]=1
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array;
}
