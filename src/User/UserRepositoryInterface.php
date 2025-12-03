<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\User;

use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Interface for user repository.
 * Defines the contract for storing and retrieving users.
 */
interface UserRepositoryInterface
{
    /**
     * Get the current user based on the user ID in the config.
     *
     * @return UserInterface|null
     */
    public function getCurrentUser(): ?UserInterface;

    /**
     * Get a user by ID.
     *
     * @param int $id
     * @return UserInterface|null
     */
    public function getById(int $id): ?UserInterface;

    /**
     * Get a user by email.
     *
     * @param string $email
     * @return UserInterface|null
     */
    public function getByEmail(string $email): ?UserInterface;

    /**
     * Update user data from the API.
     */
    public function updateFromApi(): void;

    /**
     * Refresh user data from pre-fetched data.
     * Writes the provided data to cache without fetching from API.
     *
     * @param int $userId
     * @param array<string, mixed> $data
     */
    public function refreshFromData(int $userId, array $data): void;

    /**
     * Get the default role for the current user.
     * The default role ID is read from config.json at user.defaultRole.id.
     *
     * @return RoleInterface|null
     */
    public function getCurrentUserDefaultRole(): ?RoleInterface;

    /**
     * Get all roles for the current user.
     *
     * @return array<RoleInterface>
     */
    public function getCurrentUserRoles(): array;

    /**
     * Find a role for the current user by name (case-insensitive contains).
     *
     * @param string $name The role name to search for (case-insensitive, partial match)
     * @return RoleInterface|null
     */
    public function findCurrentUserRoleByName(string $name): ?RoleInterface;
}
