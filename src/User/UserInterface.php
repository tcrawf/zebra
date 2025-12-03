<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\User;

use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Interface for user entities.
 * Defines the contract that all user implementations must follow.
 */
interface UserInterface
{
    public int $id {
        get;
    }

    public string $username {
        get;
    }

    public string $firstname {
        get;
    }

    public string $lastname {
        get;
    }

    public string $name {
        get;
    }

    public string $email {
        get;
    }

    public string|null $alternativeEmail {
        get;
    }

    public string $employeeType {
        get;
    }

    public string|null $employeeStatus {
        get;
    }

    /**
     * @var array<RoleInterface>
     */
    public array $roles {
        get;
    }

    /**
     * Find a role by name (case-insensitive contains).
     *
     * @param string $name The role name to search for (case-insensitive, partial match)
     * @return RoleInterface|null
     */
    public function findRoleByName(string $name): ?RoleInterface;

    /**
     * Find all roles matching a name (case-insensitive contains).
     *
     * @param string $name The role name to search for (case-insensitive, partial match)
     * @return array<RoleInterface>
     */
    public function findAllRolesByName(string $name): array;
}
