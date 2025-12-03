<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\User;

/**
 * Interface for user API service.
 * Defines the contract for fetching users from an external API.
 */
interface UserApiServiceInterface
{
    /**
     * Fetch all users from the API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array;

    /**
     * Fetch a single user by ID with detailed information including roles.
     *
     * @param int $id
     * @return array<string, mixed>
     */
    public function fetchById(int $id): array;
}
