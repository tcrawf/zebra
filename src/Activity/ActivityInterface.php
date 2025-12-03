<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

use Tcrawf\Zebra\EntityKey\EntityKeyInterface;

/**
 * Interface for activity entities.
 * Defines the contract that all activity implementations must follow.
 */
interface ActivityInterface
{
    public EntityKeyInterface $entityKey {
        get;
    }

    public string $name {
        get;
    }

    public string $description {
        get;
    }

    public EntityKeyInterface $projectEntityKey {
        get;
    }

    public string|null $alias {
        get;
    }
}
