<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;

/**
 * Interface for project entities.
 * Defines the contract that all project implementations must follow.
 */
interface ProjectInterface
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

    public int $status {
        get;
    }

    /**
     * @var array<ActivityInterface>
     */
    public array $activities {
        get;
    }
}
