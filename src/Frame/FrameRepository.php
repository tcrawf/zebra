<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

/**
 * Repository for storing and retrieving frames.
 * Uses JSON file storage to persist frames.
 */
class FrameRepository implements FrameRepositoryInterface
{
    private const string DEFAULT_STORAGE_FILENAME = 'frames.json';
    private const string CURRENT_FRAME_FILENAME = 'current_frame.json';
    private readonly string $storageFilename;

    /**
     * @param FrameFileStorageFactoryInterface $storageFactory
     * @param string $storageFilename The storage filename (defaults to 'frames.json')
     */
    public function __construct(
        private readonly FrameFileStorageFactoryInterface $storageFactory,
        string $storageFilename = self::DEFAULT_STORAGE_FILENAME
    ) {
        $this->storageFilename = $storageFilename;
    }

    /**
     * Save a frame to storage.
     * Frames without a stop datetime cannot be saved.
     *
     * @param FrameInterface $frame
     * @return void
     * @throws InvalidArgumentException If the frame does not have a stop datetime
     */
    public function save(FrameInterface $frame): void
    {
        // Validate that the frame has a stop datetime
        if ($frame->isActive()) {
            throw new InvalidArgumentException(
                'Cannot save a frame that does not have a stop datetime. ' .
                "Frame UUID: {$frame->uuid}"
            );
        }

        $frames = $this->loadFromStorage();

        // Store frame by UUID (will overwrite if UUID already exists)
        $frames[$frame->uuid] = $frame->toArray();

        $this->saveToStorage($frames);
    }

    /**
     * Get all frames from storage.
     *
     * @return array<FrameInterface>
     */
    public function all(): array
    {
        $framesData = $this->loadFromStorage();
        $frames = [];

        foreach ($framesData as $frameData) {
            try {
                $frames[] = FrameFactory::fromArray($frameData);
            } catch (\Exception $e) {
                // Skip frames that cannot be deserialized
                continue;
            }
        }

        return $frames;
    }

    /**
     * Get a frame by its UUID.
     *
     * @param string $uuid
     * @return FrameInterface|null
     */
    public function get(string $uuid): ?FrameInterface
    {
        $framesData = $this->loadFromStorage();

        if (!isset($framesData[$uuid])) {
            return null;
        }

        try {
            return FrameFactory::fromArray($framesData[$uuid]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get frames within a date range.
     * Filters frames by their start time.
     *
     * @param CarbonInterface|int|string $from The start datetime (inclusive)
     * @param CarbonInterface|int|string|null $to The end datetime (inclusive, optional)
     * @return array<FrameInterface>
     */
    public function getByDateRange(CarbonInterface|int|string $from, CarbonInterface|int|string|null $to = null): array
    {
        $fromTime = $this->convertToCarbon($from);
        $toTime = $to !== null ? $this->convertToCarbon($to) : null;

        $allFrames = $this->all();
        $filteredFrames = [];

        foreach ($allFrames as $frame) {
            $startTime = $frame->startTime;

            // Frame must start at or after the "from" time
            if ($startTime->lt($fromTime)) {
                continue;
            }

            // If "to" is provided, frame must start at or before the "to" time
            if ($toTime !== null && $startTime->gt($toTime)) {
                continue;
            }

            $filteredFrames[] = $frame;
        }

        return $filteredFrames;
    }

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
    ): array {
        $allFrames = $this->all();
        $filteredFrames = [];

        // Convert date range to Carbon if provided
        $fromTime = $from !== null ? $this->convertToCarbon($from)->utc() : null;
        $toTime = $to !== null ? $this->convertToCarbon($to)->utc() : null;

        foreach ($allFrames as $frame) {
            try {
                $activity = $frame->activity;
                // Extract project ID from entityKey (for Zebra source, use int ID)
                $frameProjectId = $activity->projectEntityKey->source === EntitySource::Zebra
                    && is_int($activity->projectEntityKey->id)
                    ? $activity->projectEntityKey->id
                    : null;
                $frameIssueKeys = $frame->issueKeys;
                $frameStart = $frame->startTime;
                $frameStop = $frame->stopTime;

                // Filter by project IDs (only for Zebra source projects)
                if (
                    !empty($projectIds)
                    && ($frameProjectId === null || !in_array($frameProjectId, $projectIds, true))
                ) {
                    continue;
                }

                // Ignore project IDs (only for Zebra source projects)
                if (
                    !empty($ignoreProjectIds)
                    && $frameProjectId !== null
                    && in_array($frameProjectId, $ignoreProjectIds, true)
                ) {
                    continue;
                }

                // Filter by issue keys (frame must have at least one matching issue key)
                if (!empty($issueKeys)) {
                    $hasMatchingIssue = false;
                    foreach ($issueKeys as $issueKey) {
                        if (in_array($issueKey, $frameIssueKeys, true)) {
                            $hasMatchingIssue = true;
                            break;
                        }
                    }
                    if (!$hasMatchingIssue) {
                        continue;
                    }
                }

                // Ignore issue keys (frame must not have any of these issue keys)
                if (!empty($ignoreIssueKeys)) {
                    $hasIgnoredIssue = false;
                    foreach ($ignoreIssueKeys as $ignoreIssueKey) {
                        if (in_array($ignoreIssueKey, $frameIssueKeys, true)) {
                            $hasIgnoredIssue = true;
                            break;
                        }
                    }
                    if ($hasIgnoredIssue) {
                        continue;
                    }
                }

                // Filter by date range
                if ($fromTime !== null || $toTime !== null) {
                    // Ensure frame times are in UTC for comparison
                    $frameStartUtc = $frameStart->utc();
                    // If frame has no stop time, use current time for overlap calculation
                    $effectiveStop = ($frameStop !== null ? $frameStop->utc() : Carbon::now()->utc());

                    if ($includePartialFrames) {
                        // Include frames that overlap the date range
                        // Frame overlaps if: frame.start <= toTime AND frame.stop >= fromTime
                        // We know at least one of fromTime or toTime is not null from outer condition
                        $overlaps = false;
                        if ($fromTime === null) {
                            // Only toTime is set: include if frame starts before or at toTime
                            $overlaps = $frameStartUtc->lte($toTime);
                        } elseif ($toTime === null) {
                            // Only fromTime is set: include if frame ends after or at fromTime
                            $overlaps = $effectiveStop->gte($fromTime);
                        } else {
                            // Both are set: frame overlaps if it starts before/at toTime AND ends after/at fromTime
                            $overlaps = $frameStartUtc->lte($toTime) && $effectiveStop->gte($fromTime);
                        }

                        if (!$overlaps) {
                            continue;
                        }

                        // If partial frames are included and frame extends beyond the range,
                        // we still include it (the full frame, not a partial one)
                        // This matches the Python behavior where partial frames are returned
                        // but modified to only include the overlapping portion
                        // For simplicity, we return the full frame here
                        $filteredFrames[] = $frame;
                    } else {
                        // Only include frames that are completely within the date range
                        // Frame must start at or after fromTime
                        if ($fromTime !== null && $frameStartUtc->lt($fromTime)) {
                            continue;
                        }

                        // Frame must end at or before toTime
                        if ($toTime !== null && $effectiveStop->gt($toTime)) {
                            continue;
                        }

                        $filteredFrames[] = $frame;
                    }
                } else {
                    // No date range filtering, add frame if it passed other filters
                    $filteredFrames[] = $frame;
                }
            } catch (\Exception $e) {
                // Skip frames that cannot be processed (e.g., missing activity or invalid data)
                continue;
            }
        }

        return $filteredFrames;
    }

    /**
     * Convert a time value to a CarbonInterface instance.
     * Strings are parsed in the local/system timezone, then converted to UTC.
     *
     * @param CarbonInterface|int|string $value
     * @return CarbonInterface
     */
    private function convertToCarbon(CarbonInterface|int|string $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value);
        }

        // Parse string in local timezone, then convert to UTC
        // This ensures strings without timezone info are interpreted in user's local timezone
        static $timezoneFormatter = null;
        if ($timezoneFormatter === null) {
            $timezoneFormatter = new TimezoneFormatter();
        }
        return $timezoneFormatter->parseLocalToUtc($value);
    }

    /**
     * Load frames from storage file.
     * Returns frames as an associative array keyed by UUID.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadFromStorage(): array
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $data = $storage->read();

        if (empty($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Save frames to storage file.
     *
     * @param array<string, array<string, mixed>> $frames
     * @return void
     */
    private function saveToStorage(array $frames): void
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $storage->write($frames);
    }

    /**
     * Save the current (active) frame.
     * Only one current frame can exist at a time. If a current frame already exists, it must have the same UUID.
     * The frame must be active (no stop time) and its start datetime must not be later than the current time.
     *
     * @param FrameInterface $frame
     * @return void
     * @throws InvalidArgumentException
     *   If the frame is not active, has a start time in the future, or a different current frame exists
     */
    public function saveCurrent(FrameInterface $frame): void
    {
        // Validate that the frame is active (has no stop time)
        if (!$frame->isActive()) {
            throw new InvalidArgumentException(
                'Cannot save a frame with a stop datetime as the current frame. ' .
                "Frame UUID: {$frame->uuid}"
            );
        }

        // Validate that the start time is not in the future
        $now = Carbon::now()->utc();
        $startTime = $frame->startTime;

        if ($startTime->gt($now)) {
            throw new InvalidArgumentException(
                'Cannot save a frame with a start datetime later than the current time. ' .
                "Frame UUID: {$frame->uuid}, " .
                "Start time: {$startTime->toIso8601String()}, " .
                "Current time: {$now->toIso8601String()}"
            );
        }

        // Check if a current frame already exists with a different UUID
        $existingCurrent = $this->getCurrent();
        if ($existingCurrent !== null && $existingCurrent->uuid !== $frame->uuid) {
            throw new InvalidArgumentException(
                'Cannot save a current frame: a different current frame already exists. ' .
                "Existing frame UUID: {$existingCurrent->uuid}, " .
                "New frame UUID: {$frame->uuid}"
            );
        }

        // Save the current frame (will overwrite if same UUID or create new if none exists)
        $storage = $this->storageFactory->create(self::CURRENT_FRAME_FILENAME);
        $storage->write($frame->toArray());
    }

    /**
     * Get the current (active) frame, if one exists.
     *
     * @return FrameInterface|null
     */
    public function getCurrent(): ?FrameInterface
    {
        $storage = $this->storageFactory->create(self::CURRENT_FRAME_FILENAME);

        if (!$storage->exists()) {
            return null;
        }

        $data = $storage->read();

        if (empty($data)) {
            return null;
        }

        try {
            return FrameFactory::fromArray($data);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Complete the current frame by stopping it and saving it permanently.
     * Removes the frame from current frame storage after saving.
     *
     * @param CarbonInterface|int|string|null $stopTime The stop time (defaults to current time if null)
     * @return FrameInterface The completed frame that was saved
     * @throws \RuntimeException If no current frame exists
     * @throws InvalidArgumentException If the stop time is in the future
     */
    public function completeCurrent(CarbonInterface|int|string|null $stopTime = null): FrameInterface
    {
        $currentFrame = $this->getCurrent();

        if ($currentFrame === null) {
            throw new \RuntimeException('No current frame exists to complete.');
        }

        // Use current time if stop time is not provided
        $stop = $stopTime ?? Carbon::now();

        // Convert to CarbonInterface for validation and normalize to UTC
        $stopTimeCarbon = $this->convertToCarbon($stop)->utc();
        $now = Carbon::now()->utc();

        // Validate that the stop time is not in the future
        if ($stopTimeCarbon->gt($now)) {
            throw new InvalidArgumentException(
                'Cannot complete a frame with a stop datetime later than the current time. ' .
                "Stop time: {$stopTimeCarbon->toIso8601String()}, " .
                "Current time: {$now->toIso8601String()}"
            );
        }

        // Create a completed version of the frame
        $completedFrame = FrameFactory::withStopTime($currentFrame, $stop);

        // Save the completed frame permanently
        $this->save($completedFrame);

        // Remove the current frame
        $this->clearCurrent();

        return $completedFrame;
    }

    /**
     * Remove the current frame without saving it permanently.
     *
     * @return void
     */
    public function clearCurrent(): void
    {
        $storage = $this->storageFactory->create(self::CURRENT_FRAME_FILENAME);

        if ($storage->exists()) {
            // Write an empty array to effectively clear the file
            $storage->write([]);
        }
    }

    /**
     * Update an existing frame.
     *
     * @param FrameInterface $frame
     * @return void
     */
    public function update(FrameInterface $frame): void
    {
        $frames = $this->loadFromStorage();

        // Check if frame exists
        if (!isset($frames[$frame->uuid])) {
            throw new InvalidArgumentException(
                "Cannot update frame: frame with UUID '{$frame->uuid}' does not exist."
            );
        }

        // Update the frame
        $frames[$frame->uuid] = $frame->toArray();

        $this->saveToStorage($frames);

        // If this is the current frame, update it as well
        $currentFrame = $this->getCurrent();
        if ($currentFrame !== null && $currentFrame->uuid === $frame->uuid) {
            // If the updated frame is still active, update current frame
            if ($frame->isActive()) {
                $this->saveCurrent($frame);
            } else {
                // If frame is no longer active, clear current frame
                $this->clearCurrent();
            }
        }
    }

    /**
     * Remove a frame by UUID.
     *
     * @param string $uuid
     * @return void
     */
    public function remove(string $uuid): void
    {
        $frames = $this->loadFromStorage();

        // Check if frame exists
        if (!isset($frames[$uuid])) {
            throw new InvalidArgumentException(
                "Cannot remove frame: frame with UUID '{$uuid}' does not exist."
            );
        }

        // Remove the frame
        unset($frames[$uuid]);

        $this->saveToStorage($frames);

        // If this is the current frame, clear it
        $currentFrame = $this->getCurrent();
        if ($currentFrame !== null && $currentFrame->uuid === $uuid) {
            $this->clearCurrent();
        }
    }

    /**
     * Get the last used role for a given activity.
     * Returns the role from the most recent completed frame (not active) for the activity.
     * Skips individual frames and frames without roles.
     *
     * @param ActivityInterface $activity The activity to find the last used role for
     * @return RoleInterface|null The last used role, or null if no frames exist for the activity
     */
    public function getLastUsedRoleForActivity(ActivityInterface $activity): ?RoleInterface
    {
        $allFrames = $this->all();
        $activityFrames = [];

        // Filter frames by activity entityKey, only including completed frames (not active)
        // Skip individual frames and frames without roles
        foreach ($allFrames as $frame) {
            $frameActivityKey = $frame->activity->entityKey;
            $activityKey = $activity->entityKey;

            // Compare entityKeys: same source and same ID
            $matches = $frameActivityKey->source === $activityKey->source
                && $frameActivityKey->toString() === $activityKey->toString();

            if ($matches && !$frame->isActive() && !$frame->isIndividual && $frame->role !== null) {
                $activityFrames[] = $frame;
            }
        }

        if (empty($activityFrames)) {
            return null;
        }

        // Sort frames by start time, descending (most recent first)
        usort($activityFrames, static function (FrameInterface $a, FrameInterface $b): int {
            return $b->startTime->timestamp <=> $a->startTime->timestamp;
        });

        // Return the role from the most recent frame
        return $activityFrames[0]->role;
    }

    /**
     * Get the last activity used for a given combination of issue keys.
     * Returns the activity from the most recent completed frame (not active) with the exact same issue keys.
     * Issue key order is ignored when matching (e.g., ['ABC-123', 'DEF-456'] matches ['DEF-456', 'ABC-123']).
     *
     * @param array<string> $issueKeys The issue keys to find the last activity for
     * @return ActivityInterface|null The last activity, or null if no frames exist with these issue keys
     */
    public function getLastActivityForIssueKeys(array $issueKeys): ?ActivityInterface
    {
        if (empty($issueKeys)) {
            return null;
        }

        $allFrames = $this->all();
        $matchingFrames = [];

        // Sort issue keys for comparison (order-independent matching)
        $sortedIssueKeys = $issueKeys;
        sort($sortedIssueKeys);

        // Filter frames by matching issue keys, only including completed frames (not active)
        foreach ($allFrames as $frame) {
            // Skip active frames
            if ($frame->isActive()) {
                continue;
            }

            $frameIssueKeys = $frame->issueKeys;

            // Skip frames with no issue keys if we're looking for specific issue keys
            if (empty($frameIssueKeys)) {
                continue;
            }

            // Sort frame issue keys for comparison
            $sortedFrameIssueKeys = $frameIssueKeys;
            sort($sortedFrameIssueKeys);

            // Check if issue keys match (order-independent)
            if ($sortedFrameIssueKeys === $sortedIssueKeys) {
                $matchingFrames[] = $frame;
            }
        }

        if (empty($matchingFrames)) {
            return null;
        }

        // Sort frames by start time, descending (most recent first)
        usort($matchingFrames, static function (FrameInterface $a, FrameInterface $b): int {
            return $b->startTime->timestamp <=> $a->startTime->timestamp;
        });

        // Return the activity from the most recent frame
        return $matchingFrames[0]->activity;
    }
}
