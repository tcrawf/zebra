<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\User;

use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Pure data entity for users.
 * Stores user information from the API.
 */
readonly class User implements UserInterface
{
    /**
     * @param int $id
     * @param string $username
     * @param string $firstname
     * @param string $lastname
     * @param string $name
     * @param string $email
     * @param string|null $alternativeEmail
     * @param string $employeeType
     * @param string|null $employeeStatus
     * @param array<RoleInterface> $roles
     */
    public function __construct(
        public int $id,
        public string $username,
        public string $firstname,
        public string $lastname,
        public string $name,
        public string $email,
        public string|null $alternativeEmail = null,
        public string $employeeType = '',
        public string|null $employeeStatus = null,
        /**
         * @var array<RoleInterface>
         */
        public array $roles = []
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            'User(id=%d, username=%s, name=%s, email=%s)',
            $this->id,
            $this->username,
            $this->name,
            $this->email
        );
    }

    /**
     * Find a role by name (case-insensitive contains).
     *
     * @param string $name The role name to search for (case-insensitive, partial match)
     * @return RoleInterface|null
     */
    public function findRoleByName(string $name): ?RoleInterface
    {
        $nameLower = strtolower($name);
        foreach ($this->roles as $role) {
            if (str_contains(strtolower($role->name), $nameLower)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Find all roles matching a name (case-insensitive contains).
     *
     * @param string $name The role name to search for (case-insensitive, partial match)
     * @return array<RoleInterface>
     */
    public function findAllRolesByName(string $name): array
    {
        $nameLower = strtolower($name);
        $matches = [];
        foreach ($this->roles as $role) {
            if (str_contains(strtolower($role->name), $nameLower)) {
                $matches[] = $role;
            }
        }

        return $matches;
    }
}
