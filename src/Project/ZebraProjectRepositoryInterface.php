<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

/**
 * Interface for Zebra project repository.
 * Defines the contract for storing and retrieving projects from Zebra API.
 */
interface ZebraProjectRepositoryInterface extends ProjectRepositoryReadInterface
{
    /**
     * Update project data from the API.
     * Always fetches all projects regardless of status.
     * Updates the static cache with the new data.
     */
    public function updateFromApi(): void;

    /**
     * Refresh project data from pre-fetched data.
     * Writes the provided data to cache and updates static cache without fetching from API.
     *
     * @param array<int, array<string, mixed>> $data
     */
    public function refreshFromData(array $data): void;
}
