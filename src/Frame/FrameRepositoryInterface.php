<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Role\RoleInterface;

/**
 * Interface for frame repository.
 * Defines the contract for storing and retrieving frames.
 */
interface FrameRepositoryInterface
{
    /**
     * Save a frame to storage.
     * Frames without a stop datetime cannot be saved.
     *
     * @param FrameInterface $frame
     * @return void
     * @throws \InvalidArgumentException
     *   If the frame does not have a stop datetime
     */
    public function save(FrameInterface $frame): void;

    /**
     * Get all frames from storage.
     *
     * @return array<FrameInterface>
     */
    public function all(): array;

    /**
     * Get a frame by its UUID.
     *
     * @param string $uuid
     * @return FrameInterface|null
     */
    public function get(string $uuid): ?FrameInterface;

    /**
     * Get frames within a date range.
     * Filters frames by their start time.
     *
     * @param CarbonInterface|int|string $from The start datetime (inclusive)
     * @param CarbonInterface|int|string|null $to The end datetime (inclusive, optional)
     * @return array<FrameInterface>
     */
    public function getByDateRange(CarbonInterface|int|string $from, CarbonInterface|int|string|null $to = null): array;

    /**
     * Filter frames based on multiple criteria.
     * All criteria are optional and can be combined.
     *
     * @param array<int>|null $projectIds Filter by project IDs (via activity)
     * @param array<string>|null $issueKeys Filter by issue keys
     * @param array<int>|null $ignoreProjectIds Exclude frames with these project IDs
     * @param array<string>|null $ignoreIssueKeys Exclude frames with these issue keys
     * @param CarbonInterface|int|string|null $from Start of date range (inclusive)
     * @param CarbonInterface|int|string|null $to End of date range (inclusive)
     * @param bool $includePartialFrames If true, include frames that partially overlap the date range
     * @return array<FrameInterface>
     */
    public function filter(
        ?array $projectIds = null,
        ?array $issueKeys = null,
        ?array $ignoreProjectIds = null,
        ?array $ignoreIssueKeys = null,
        CarbonInterface|int|string|null $from = null,
        CarbonInterface|int|string|null $to = null,
        bool $includePartialFrames = false
    ): array;

    /**
     * Save the current (active) frame.
     * Only one current frame can exist at a time. If a current frame already exists, it must have the same UUID.
     * The frame must be active (no stop time) and its start datetime must not be later than the current time.
     *
     * @param FrameInterface $frame
     * @return void
     * @throws \InvalidArgumentException
     *   If the frame is not active, has a start time in the future, or a different current frame exists
     */
    public function saveCurrent(FrameInterface $frame): void;

    /**
     * Get the current (active) frame, if one exists.
     *
     * @return FrameInterface|null
     */
    public function getCurrent(): ?FrameInterface;

    /**
     * Complete the current frame by stopping it and saving it permanently.
     * Removes the frame from current frame storage after saving.
     *
     * @param CarbonInterface|int|string|null $stopTime The stop time (defaults to current time if null)
     * @return FrameInterface The completed frame that was saved
     * @throws \RuntimeException If no current frame exists
     * @throws \InvalidArgumentException If the stop time is in the future
     */
    public function completeCurrent(CarbonInterface|int|string|null $stopTime = null): FrameInterface;

    /**
     * Remove the current frame without saving it permanently.
     *
     * @return void
     */
    public function clearCurrent(): void;

    /**
     * Update an existing frame.
     *
     * @param FrameInterface $frame
     * @return void
     */
    public function update(FrameInterface $frame): void;

    /**
     * Remove a frame by UUID.
     *
     * @param string $uuid
     * @return void
     */
    public function remove(string $uuid): void;

    /**
     * Get the last used role for a given activity.
     * Returns the role from the most recent completed frame (not active) for the activity.
     *
     * @param ActivityInterface $activity The activity to find the last used role for
     * @return RoleInterface|null The last used role, or null if no frames exist for the activity
     */
    public function getLastUsedRoleForActivity(ActivityInterface $activity): ?RoleInterface;
}
