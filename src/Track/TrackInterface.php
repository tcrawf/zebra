<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Track;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Exception\FrameAlreadyStartedException;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Interface for Track service.
 * Provides Watson-like functionality for starting, stopping, adding, and canceling frames.
 */
interface TrackInterface
{
    /**
     * Start tracking a new frame.
     *
     * @param Activity $activity The activity to track
     * @param string|null $description Optional description for the frame
     * @param CarbonInterface|null $startAt Optional start time (defaults to current time)
     * @param bool $gap If false, start time will be set to the previous frame's stop time
     * @param bool $isIndividual If true, frame is an individual action and doesn't require a role
     * @param RoleInterface|null $role Optional role (defaults to user's default role if not individual)
     * @return FrameInterface The started frame
     * @throws FrameAlreadyStartedException If a frame is already started
     * @throws InvalidTimeException If start time is invalid (in future, before previous frame ends)
     */
    public function start(
        Activity $activity,
        ?string $description = null,
        ?CarbonInterface $startAt = null,
        bool $gap = true,
        bool $isIndividual = false,
        ?RoleInterface $role = null
    ): FrameInterface;

    /**
     * Stop the current frame.
     *
     * @param CarbonInterface|null $stopAt Optional stop time (defaults to current time)
     * @return FrameInterface The completed frame
     * @throws NoFrameStartedException If no frame is started
     * @throws InvalidTimeException If stop time is invalid (in future, before start time)
     */
    public function stop(?CarbonInterface $stopAt = null): FrameInterface;

    /**
     * Add a completed frame (with both start and stop times).
     *
     * @param Activity $activity The activity to track
     * @param CarbonInterface $fromDate The start time
     * @param CarbonInterface $toDate The stop time
     * @param string|null $description Optional description for the frame
     * @param bool $isIndividual If true, frame is an individual action and doesn't require a role
     * @param RoleInterface|null $role Optional role (defaults to user's default role if not individual)
     * @return FrameInterface The added frame
     * @throws InvalidTimeException If date range is invalid (fromDate > toDate)
     */
    public function add(
        Activity $activity,
        CarbonInterface $fromDate,
        CarbonInterface $toDate,
        ?string $description = null,
        bool $isIndividual = false,
        ?RoleInterface $role = null
    ): FrameInterface;

    /**
     * Cancel the current frame without saving it.
     *
     * @return FrameInterface The cancelled frame
     * @throws NoFrameStartedException If no frame is started
     */
    public function cancel(): FrameInterface;

    /**
     * Check if a frame is currently started.
     *
     * @return bool True if a frame is started, false otherwise
     */
    public function isStarted(): bool;

    /**
     * Get the current frame, if one exists.
     *
     * @return FrameInterface|null The current frame, or null if none exists
     */
    public function getCurrent(): ?FrameInterface;
}
