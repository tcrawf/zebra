<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Exception\FrameUuidCollisionException;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

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
     * @param ActivityRepositoryInterface $activityRepository
     * @param UserRepositoryInterface $userRepository
     * @param string $storageFilename The storage filename (defaults to 'frames.json')
     */
    public function __construct(
        private readonly FrameFileStorageFactoryInterface $storageFactory,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly UserRepositoryInterface $userRepository,
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
     * @throws FrameUuidCollisionException If the UUID already exists with different data
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
        $payload = $frame->toArray();

        if (isset($frames[$frame->uuid])) {
            if (!$this->framePayloadsAreEquivalent($frames[$frame->uuid], $payload)) {
                throw new FrameUuidCollisionException(
                    "Cannot save frame: UUID {$frame->uuid} already exists with different data."
                );
            }

            return;
        }

        $frames[$frame->uuid] = $payload;

        $this->saveToStorage($frames);
    }

    /**
     * {@inheritdoc}
     */
    public function allocateUnusedFrameUuid(): UuidInterface
    {
        /** @var array<string, true> $used */
        $used = [];
        foreach (array_keys($this->loadFromStorage()) as $uuid) {
            $used[$uuid] = true;
        }

        $current = $this->getCurrent();
        if ($current !== null) {
            $used[$current->uuid] = true;
        }

        $maxAttempts = 256;
        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $candidate = Uuid::random();
            if (!isset($used[$candidate->getHex()])) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "Failed to allocate an unused frame UUID after {$maxAttempts} attempts."
        );
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $incoming
     */
    private function framePayloadsAreEquivalent(array $stored, array $incoming): bool
    {
        $a = $stored;
        $b = $incoming;
        $this->ksortRecursive($a);
        $this->ksortRecursive($b);

        return $a === $b;
    }

    /**
     * @param array<mixed> $array
     */
    private function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        unset($value);
        ksort($array);
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
                $frames[] = FrameFactory::fromArray($frameData, $this->activityRepository, $this->userRepository);
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
            return FrameFactory::fromArray($framesData[$uuid], $this->activityRepository, $this->userRepository);
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

        $framesData = $this->loadFromStorage();
        $filteredFrames = [];
        $fromTimestamp = $fromTime->timestamp;
        $toTimestamp = $toTime?->timestamp;

        foreach ($framesData as $frameData) {
            if (!$this->rawFrameStartsInDateRange($frameData, $fromTimestamp, $toTimestamp)) {
                continue;
            }

            try {
                $filteredFrames[] = FrameFactory::fromArray(
                    $frameData,
                    $this->activityRepository,
                    $this->userRepository
                );
            } catch (\Exception $e) {
                continue;
            }
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
        $framesData = $this->loadFromStorage();
        $filteredFrames = [];

        // Convert date range to Carbon if provided
        $fromTime = $from !== null ? $this->convertToCarbon($from)->utc() : null;
        $toTime = $to !== null ? $this->convertToCarbon($to)->utc() : null;
        $fromTimestamp = $fromTime?->timestamp;
        $toTimestamp = $toTime?->timestamp;
        $nowTimestamp = Carbon::now()->utc()->timestamp;

        foreach ($framesData as $frameData) {
            try {
                if (
                    !$this->rawFrameMatchesIssueFilters($frameData, $issueKeys, $ignoreIssueKeys)
                    || !$this->rawFrameMatchesDateRange(
                        $frameData,
                        $fromTimestamp,
                        $toTimestamp,
                        $includePartialFrames,
                        $nowTimestamp
                    )
                ) {
                    continue;
                }

                $frame = FrameFactory::fromArray($frameData, $this->activityRepository, $this->userRepository);
                if (!$this->frameMatchesProjectFilters($frame, $projectIds, $ignoreProjectIds)) {
                    continue;
                }

                $filteredFrames[] = $frame;
            } catch (\Exception $e) {
                // Skip frames that cannot be processed (e.g., missing activity or invalid data)
                continue;
            }
        }

        return $filteredFrames;
    }

    /**
     * @param array<string, mixed> $frameData
     */
    private function rawFrameStartsInDateRange(array $frameData, int $fromTimestamp, ?int $toTimestamp): bool
    {
        $startTimestamp = $this->getRawTimestamp($frameData, 'start');
        if ($startTimestamp === null) {
            return false;
        }

        if ($startTimestamp < $fromTimestamp) {
            return false;
        }

        return $toTimestamp === null || $startTimestamp <= $toTimestamp;
    }

    /**
     * @param array<string, mixed> $frameData
     * @param array<string>|null $issueKeys
     * @param array<string>|null $ignoreIssueKeys
     */
    private function rawFrameMatchesIssueFilters(
        array $frameData,
        ?array $issueKeys,
        ?array $ignoreIssueKeys
    ): bool {
        if (empty($issueKeys) && empty($ignoreIssueKeys)) {
            return true;
        }

        $frameIssueKeys = $this->getRawIssueKeys($frameData);

        if (!empty($issueKeys)) {
            $hasMatchingIssue = false;
            foreach ($issueKeys as $issueKey) {
                if (in_array($issueKey, $frameIssueKeys, true)) {
                    $hasMatchingIssue = true;
                    break;
                }
            }

            if (!$hasMatchingIssue) {
                return false;
            }
        }

        if (!empty($ignoreIssueKeys)) {
            foreach ($ignoreIssueKeys as $ignoreIssueKey) {
                if (in_array($ignoreIssueKey, $frameIssueKeys, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $frameData
     */
    private function rawFrameMatchesDateRange(
        array $frameData,
        ?int $fromTimestamp,
        ?int $toTimestamp,
        bool $includePartialFrames,
        int $nowTimestamp
    ): bool {
        if ($fromTimestamp === null && $toTimestamp === null) {
            return true;
        }

        $startTimestamp = $this->getRawTimestamp($frameData, 'start');
        if ($startTimestamp === null) {
            return false;
        }

        $stopTimestamp = $this->getRawTimestamp($frameData, 'stop') ?? $nowTimestamp;

        if ($includePartialFrames) {
            if ($fromTimestamp === null) {
                return $startTimestamp <= $toTimestamp;
            }

            if ($toTimestamp === null) {
                return $stopTimestamp >= $fromTimestamp;
            }

            return $startTimestamp <= $toTimestamp && $stopTimestamp >= $fromTimestamp;
        }

        if ($fromTimestamp !== null && $startTimestamp < $fromTimestamp) {
            return false;
        }

        return $toTimestamp === null || $stopTimestamp <= $toTimestamp;
    }

    /**
     * @param array<string, mixed> $frameData
     */
    private function getRawTimestamp(array $frameData, string $key): ?int
    {
        $value = $frameData[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $frameData
     * @return array<string>
     */
    private function getRawIssueKeys(array $frameData): array
    {
        if (isset($frameData['issues']) && is_array($frameData['issues'])) {
            return array_values(array_filter($frameData['issues'], static fn($issueKey) => is_string($issueKey)));
        }

        $description = $frameData['desc'] ?? '';
        if (!is_string($description) || $description === '') {
            return [];
        }

        preg_match_all('/[A-Z]{2,6}-\d{1,5}/', $description, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param array<int>|null $projectIds
     * @param array<int>|null $ignoreProjectIds
     */
    private function frameMatchesProjectFilters(
        FrameInterface $frame,
        ?array $projectIds,
        ?array $ignoreProjectIds
    ): bool {
        if (empty($projectIds) && empty($ignoreProjectIds)) {
            return true;
        }

        $activity = $frame->activity;
        $frameProjectId = $activity->projectEntityKey->source === EntitySource::Zebra
            && is_int($activity->projectEntityKey->id)
            ? $activity->projectEntityKey->id
            : null;

        if (!empty($projectIds) && ($frameProjectId === null || !in_array($frameProjectId, $projectIds, true))) {
            return false;
        }

        return empty($ignoreProjectIds)
            || $frameProjectId === null
            || !in_array($frameProjectId, $ignoreProjectIds, true);
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
            return FrameFactory::fromArray($data, $this->activityRepository, $this->userRepository);
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
        $completedFrame = FrameFactory::withStopTime(
            $currentFrame,
            $stop,
            $this->activityRepository,
            $this->userRepository
        );

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
            $frameActivityKey = $frame->activityKey;
            $activityKey = $activity->entityKey;

            // Compare entityKeys: same source and same ID
            $matches = $frameActivityKey->source === $activityKey->source
                && $frameActivityKey->toString() === $activityKey->toString();

            // Filter on the cheap `roleId` property — avoids triggering
            // the lazy `role` getter (which would hit the user repository)
            // for every frame. Only the winner's `->role` is dereferenced
            // below, after the sort.
            if ($matches && !$frame->isActive() && !$frame->isIndividual && $frame->roleId !== null) {
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
