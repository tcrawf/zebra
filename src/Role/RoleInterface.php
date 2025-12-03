<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Role;

/**
 * Interface for role entities.
 * Defines the contract that all role implementations must follow.
 */
interface RoleInterface
{
    public int $id {
        get;
    }

    public int|null $parentId {
        get;
    }

    public string $name {
        get;
    }

    public string $fullName {
        get;
    }

    public string $type {
        get;
    }

    public string $status {
        get;
    }
}
