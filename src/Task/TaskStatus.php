<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

/**
 * Enum for task status values.
 */
enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in-progress';
    case Complete = 'complete';

    /**
     * Check if this status matches the given status value.
     *
     * @param string $status
     * @return bool
     */
    public function matches(string $status): bool
    {
        return $this->value === $status;
    }
}
