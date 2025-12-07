<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\ActivityInterface;

/**
 * Interface for task entities.
 * Defines the contract that all task implementations must follow.
 */
interface TaskInterface
{
    public string $uuid {
        get;
    }

    public string $summary {
        get;
    }

    public array $issueTags {
        get;
    }

    public ActivityInterface|null $activity {
        get;
    }

    public CarbonInterface $createdAt {
        get;
    }

    public CarbonInterface|null $dueAt {
        get;
    }

    public CarbonInterface|null $completedAt {
        get;
    }

    public string $completionNote {
        get;
    }

    public TaskStatus $status {
        get;
    }

    /**
     * Get the creation timestamp.
     */
    public function getCreatedAtTimestamp(): int;

    /**
     * Get the due timestamp, or null if not set.
     */
    public function getDueAtTimestamp(): int|null;

    /**
     * Get the completion timestamp, or null if not set.
     */
    public function getCompletedAtTimestamp(): int|null;

    /**
     * Export task data as an associative array.
     */
    public function toArray(): array;

    /**
     * String representation of the task.
     */
    public function __toString(): string;
}
