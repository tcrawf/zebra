<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Interface for time tracking frame entities.
 * Defines the contract that all frame implementations must follow.
 */
interface FrameInterface
{
    public string $uuid {
        get;
    }

    /**
     * Get the start time as a CarbonInterface object.
     */
    public CarbonInterface $startTime {
        get;
    }

    /**
     * Get the stop time as a CarbonInterface object, or null if not set.
     */
    public CarbonInterface|null $stopTime {
        get;
    }

    /**
     * Get the start timestamp.
     */
    public function getStartTimestamp(): int;

    /**
     * Get the stop timestamp, or null if not set.
     */
    public function getStopTimestamp(): int|null;

    public Activity $activity {
        get;
    }

    public array $issueKeys {
        get;
    }

    public string $description {
        get;
    }

    public bool $isIndividual {
        get;
    }

    public RoleInterface|null $role {
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
     * Check if this frame is currently active (no stop time).
     */
    public function isActive(): bool;

    /**
     * Get the duration in seconds, or null if frame is active.
     */
    public function getDuration(): int|null;

    /**
     * Export frame data as an associative array.
     */
    public function toArray(): array;

    /**
     * Less than comparison.
     */
    public function isLessThan(FrameInterface $other): bool;

    /**
     * Less than or equal comparison.
     */
    public function isLessThanOrEqual(FrameInterface $other): bool;

    /**
     * Greater than comparison.
     */
    public function isGreaterThan(FrameInterface $other): bool;

    /**
     * Greater than or equal comparison.
     */
    public function isGreaterThanOrEqual(FrameInterface $other): bool;

    /**
     * String representation of the frame.
     */
    public function __toString(): string;
}
