<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Interface for timesheet entities.
 * Defines the contract that all timesheet implementations must follow.
 */
interface TimesheetInterface
{
    public string $uuid {
        get;
    }

    public ActivityInterface $activity {
        get;
    }

    /**
     * Get the project ID from the activity's project entity key.
     */
    public function getProjectId(): int;

    public string $description {
        get;
    }

    public string|null $clientDescription {
        get;
    }

    public float $time {
        get;
    }

    /**
     * Get the date as a CarbonInterface object.
     */
    public CarbonInterface $date {
        get;
    }

    public RoleInterface|null $role {
        get;
    }

    public bool $individualAction {
        get;
    }

    /**
     * Get the UUIDs of frames used to create this timesheet.
     *
     * @return array<string>
     */
    public array $frameUuids {
        get;
    }

    /**
     * Get the Zebra API ID, or null if not yet pushed to Zebra.
     */
    public int|null $zebraId {
        get;
    }

    /**
     * Get the updated at time as a CarbonInterface object.
     */
    public CarbonInterface $updatedAt {
        get;
    }

    /**
     * Get the updated at timestamp.
     */
    public function getUpdatedAtTimestamp(): int;

    /**
     * Get the date timestamp.
     */
    public function getDateTimestamp(): int;

    public bool $doNotSync {
        get;
    }

    /**
     * Export timesheet data as an associative array.
     */
    public function toArray(): array;

    /**
     * String representation of the timesheet.
     */
    public function __toString(): string;
}
