<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\User;

use Tcrawf\Zebra\Cache\CacheFileStorageFactoryInterface;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Repository for storing and retrieving users.
 * Uses JSON file storage and fetches from API when needed.
 * All users loaded by ID are cached in user_{id}.json files.
 */
class UserRepository implements UserRepositoryInterface
{
    /**
     * @param UserApiServiceInterface $apiService
     * @param CacheFileStorageFactoryInterface $cacheStorageFactory
     * @param ConfigFileStorageInterface $configStorage
     */
    public function __construct(
        private readonly UserApiServiceInterface $apiService,
        private readonly CacheFileStorageFactoryInterface $cacheStorageFactory,
        private readonly ConfigFileStorageInterface $configStorage
    ) {
    }

    /**
     * Get the configured user ID from config.
     *
     * @return int|null
     */
    private function getConfigUserId(): ?int
    {
        $userId = $this->configStorage->get('user.id');
        if ($userId === null) {
            return null;
        }
        // Handle both string and integer values
        if (is_int($userId)) {
            return $userId;
        }
        if (is_string($userId) && ctype_digit($userId)) {
            return (int) $userId;
        }
        return null;
    }

    /**
     * Get the current user based on the user ID in the config.
     *
     * @return UserInterface|null
     */
    public function getCurrentUser(): ?UserInterface
    {
        $userId = $this->getConfigUserId();
        if ($userId === null) {
            return null;
        }

        return $this->getById($userId);
    }

    /**
     * Get a user by ID.
     * Returns cached data if available, otherwise fetches from API and caches the result.
     * All users loaded by ID are cached in user_{id}.json files.
     *
     * @param int $id
     * @return UserInterface|null
     */
    public function getById(int $id): ?UserInterface
    {
        // Check cache first
        $cachedUser = $this->loadFromCache($id);
        if ($cachedUser !== null) {
            return $cachedUser;
        }

        // Fetch detailed user data (including roles) from the single user endpoint
        $userData = $this->apiService->fetchById($id);

        // Cache the user data in user_{id}.json
        $this->saveToCache($id, $userData);

        // Load and return the cached user
        return $this->loadFromCache($id);
    }

    /**
     * Get a user by email.
     * Finds the user ID by email, then uses getById() which caches the result.
     *
     * @param string $email
     * @return UserInterface|null
     */
    public function getByEmail(string $email): ?UserInterface
    {
        // First, fetch all users to find the user ID by email
        $allUsers = $this->apiService->fetchAll();
        $userId = $this->findUserIdByEmail($allUsers, $email);

        if ($userId === null) {
            return null;
        }

        // Use getById which will cache the user
        return $this->getById($userId);
    }

    /**
     * Update user data from the API.
     * Only updates the user whose ID is in config.json.
     */
    public function updateFromApi(): void
    {
        $userId = $this->getConfigUserId();
        if ($userId === null) {
            return;
        }

        $userData = $this->apiService->fetchById($userId);
        $this->saveToCache($userId, $userData);
    }

    /**
     * Refresh user data from pre-fetched data.
     * Writes the provided data to cache without fetching from API.
     *
     * @param int $userId
     * @param array<string, mixed> $data
     */
    public function refreshFromData(int $userId, array $data): void
    {
        $this->saveToCache($userId, $data);
    }

    /**
     * Get the default role for the current user.
     * The default role ID is read from config.json at user.defaultRole.id.
     *
     * @return RoleInterface|null
     */
    public function getCurrentUserDefaultRole(): ?RoleInterface
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return null;
        }

        $defaultRoleId = $this->configStorage->get('user.defaultRole.id');
        if ($defaultRoleId === null) {
            return null;
        }

        // Handle both string and integer values
        $roleId = null;
        if (is_int($defaultRoleId)) {
            $roleId = $defaultRoleId;
        } elseif (is_string($defaultRoleId) && ctype_digit($defaultRoleId)) {
            $roleId = (int) $defaultRoleId;
        }

        if ($roleId === null) {
            return null;
        }

        // Find the role in the user's roles array
        foreach ($user->roles as $role) {
            if ($role->id === $roleId) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Get all roles for the current user.
     *
     * @return array<RoleInterface>
     */
    public function getCurrentUserRoles(): array
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return [];
        }

        return $user->roles;
    }

    /**
     * Find a role for the current user by name (case-insensitive contains).
     *
     * @param string $name The role name to search for (case-insensitive, partial match)
     * @return RoleInterface|null
     */
    public function findCurrentUserRoleByName(string $name): ?RoleInterface
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return null;
        }

        return $user->findRoleByName($name);
    }

    /**
     * Find a user ID by email in the users array.
     *
     * @param array<int, array<string, mixed>> $users
     * @param string $email
     * @return int|null
     */
    private function findUserIdByEmail(array $users, string $email): ?int
    {
        $emailLower = strtolower($email);
        foreach ($users as $id => $userData) {
            if (isset($userData['email']) && strtolower($userData['email']) === $emailLower) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * Get the cache filename for a user ID.
     *
     * @param int $userId
     * @return string
     */
    private function getCacheFilename(int $userId): string
    {
        return 'user_' . $userId . '.json';
    }

    /**
     * Load user from cached JSON file.
     *
     * @param int $userId
     * @return UserInterface|null
     */
    private function loadFromCache(int $userId): ?UserInterface
    {
        $cacheFilename = $this->getCacheFilename($userId);
        $cacheStorage = $this->cacheStorageFactory->create($cacheFilename);
        $data = $cacheStorage->read();
        if (empty($data) || !isset($data['user'])) {
            return null;
        }
        return $this->createUserFromArray($data);
    }

    /**
     * Save user to cached JSON file.
     * Saves to user_{id}.json format.
     *
     * @param int $userId
     * @param array<string, mixed> $data
     */
    private function saveToCache(int $userId, array $data): void
    {
        $cacheFilename = $this->getCacheFilename($userId);
        $cacheStorage = $this->cacheStorageFactory->create($cacheFilename);
        $cacheStorage->write($data);
    }

    /**
     * Create a User entity from array data.
     *
     * @param array<string, mixed> $data
     * @return UserInterface
     */
    private function createUserFromArray(array $data): UserInterface
    {
        $userData = $data['user'];
        $roles = [];

        // Extract roles from the data
        if (isset($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $roleData) {
                if (is_array($roleData)) {
                    $roles[] = $this->createRoleFromArray($roleData);
                }
            }
        }

        return new User(
            $userData['id'],
            $userData['username'] ?? '',
            $userData['firstname'] ?? '',
            $userData['lastname'] ?? '',
            $userData['name'] ?? '',
            $userData['email'] ?? '',
            $userData['alternative_email'] ?? null,
            $userData['employee_type'] ?? '',
            $userData['employee_status'] ?? null,
            $roles
        );
    }

    /**
     * Create a Role entity from array data.
     *
     * @param array<string, mixed> $roleData
     * @return RoleInterface
     */
    private function createRoleFromArray(array $roleData): RoleInterface
    {
        return new Role(
            $roleData['id'],
            $roleData['parent_id'] ?? null,
            $roleData['name'] ?? '',
            $roleData['full_name'] ?? '',
            $roleData['type'] ?? '',
            $roleData['status'] ?? ''
        );
    }
}
