<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

/**
 * Enum for project status values.
 */
enum ProjectStatus: int
{
    case Inactive = 0;
    case Active = 1;
    case Other = 2;

    /**
     * Check if this status matches the given status value.
     *
     * @param int $status
     * @return bool
     */
    public function matches(int $status): bool
    {
        return $this->value === $status;
    }
}
