<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Track;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Exception\FrameAlreadyStartedException;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Frame\FrameFactory;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;

/**
 * Service class for tracking time frames.
 * Provides Watson-like functionality for starting, stopping, adding, and canceling frames.
 */
readonly class Track implements TrackInterface
{
    /**
     * Initialize the Track service with required dependencies.
     *
     * @param FrameRepositoryInterface $frameRepository Repository for frame storage operations
     * @param ConfigFileStorageInterface $config Configuration storage
     * @param ProjectRepositoryInterface $projectRepository Repository for project/activity lookup
     * @param UserRepositoryInterface $userRepository Repository for user and role information
     * @param TimezoneFormatter $timezoneFormatter Timezone formatting service
     */
    public function __construct(
        private FrameRepositoryInterface $frameRepository,
        /** @phpstan-ignore-next-line property.onlyWritten */
        private ConfigFileStorageInterface $config,
        /** @phpstan-ignore-next-line property.onlyWritten */
        private ProjectRepositoryInterface $projectRepository,
        private UserRepositoryInterface $userRepository,
        private TimezoneFormatter $timezoneFormatter
    ) {
    }

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
    ): FrameInterface {
        // Check if frame is already started
        $currentFrame = $this->frameRepository->getCurrent();
        if ($currentFrame !== null) {
            // Convert start time to system timezone for display
            $startTimeLocal = $this->timezoneFormatter->toLocal($currentFrame->startTime);
            $roleInfo = $currentFrame->isIndividual
                ? 'Individual'
                : ($currentFrame->role !== null ? $currentFrame->role->name : 'No role');
            throw new FrameAlreadyStartedException(
                sprintf(
                    'A frame is already started. Stop or cancel the current frame before starting a new one. ' .
                    'Current frame: UUID=%s, Start=%s, Activity=%s, Role=%s',
                    $currentFrame->uuid,
                    $startTimeLocal->toIso8601String(),
                    $currentFrame->activity->name,
                    $roleInfo
                )
            );
        }

        // Determine start time
        $now = Carbon::now()->utc();
        $startTime = $startAt !== null ? $startAt->utc() : $now;

        // Handle gap/no-gap logic
        if (!$gap) {
            $lastFrame = $this->getLastFrame();
            if ($lastFrame !== null && $lastFrame->stopTime !== null) {
                $startTime = $lastFrame->stopTime;
            }
        }

        // Validate start time isn't in the future
        if ($startTime->gt($now)) {
            $startTimeLocal = $this->timezoneFormatter->toLocal($startTime);
            $nowLocal = $this->timezoneFormatter->toLocal($now);
            throw new InvalidTimeException(
                'Cannot start a frame in the future. ' .
                "Start time: {$startTimeLocal->toIso8601String()}, " .
                "Current time: {$nowLocal->toIso8601String()}"
            );
        }

        // Validate start time isn't before previous frame ends (if gap is true)
        if ($gap) {
            $lastFrame = $this->getLastFrame();
            if ($lastFrame !== null && $lastFrame->stopTime !== null) {
                if ($startTime->lt($lastFrame->stopTime)) {
                    $startTimeLocal = $this->timezoneFormatter->toLocal($startTime);
                    $lastFrameStopLocal = $this->timezoneFormatter->toLocal($lastFrame->stopTime);
                    throw new InvalidTimeException(
                        'Cannot start a frame before the previous frame ends. ' .
                        "Start time: {$startTimeLocal->toIso8601String()}, " .
                        "Previous frame stop time: {$lastFrameStopLocal->toIso8601String()}"
                    );
                }
            }
        }

        // Get role (use provided role or default to user's default role, unless individual)
        if (!$isIndividual) {
            if ($role === null) {
                $role = $this->userRepository->getCurrentUserDefaultRole();
                if ($role === null) {
                    throw new \RuntimeException(
                        'No default role found. Please configure user.defaultRole.id in config.json.'
                    );
                }
            }
        } else {
            // Individual frames don't require a role
            $role = null;
        }

        // Create frame
        $frame = FrameFactory::create(
            $startTime,
            null, // No stop time (active frame)
            $activity,
            $isIndividual,
            $role,
            $description ?? ''
        );

        // Save as current frame
        $this->frameRepository->saveCurrent($frame);

        return $frame;
    }

    /**
     * Stop the current frame.
     *
     * @param CarbonInterface|null $stopAt Optional stop time (defaults to current time)
     * @return FrameInterface The completed frame
     * @throws NoFrameStartedException If no frame is started
     * @throws InvalidTimeException If stop time is invalid (in future, before start time)
     */
    public function stop(?CarbonInterface $stopAt = null): FrameInterface
    {
        // Check if frame is started
        $currentFrame = $this->frameRepository->getCurrent();
        if ($currentFrame === null) {
            throw new NoFrameStartedException('No frame is started. Start a frame before stopping.');
        }

        // Determine stop time
        $now = Carbon::now()->utc();
        $stopTime = $stopAt !== null ? $stopAt->utc() : $now;

        // Validate stop time isn't in the future
        if ($stopTime->gt($now)) {
            $stopTimeLocal = $this->timezoneFormatter->toLocal($stopTime);
            $nowLocal = $this->timezoneFormatter->toLocal($now);
            throw new InvalidTimeException(
                'Cannot stop a frame in the future. ' .
                "Stop time: {$stopTimeLocal->toIso8601String()}, " .
                "Current time: {$nowLocal->toIso8601String()}"
            );
        }

        // Validate stop time isn't before start time
        if ($stopTime->lt($currentFrame->startTime)) {
            $stopTimeLocal = $this->timezoneFormatter->toLocal($stopTime);
            $startTimeLocal = $this->timezoneFormatter->toLocal($currentFrame->startTime);
            throw new InvalidTimeException(
                'Cannot stop a frame before it starts. ' .
                "Stop time: {$stopTimeLocal->toIso8601String()}, " .
                "Start time: {$startTimeLocal->toIso8601String()}"
            );
        }

        // Complete the current frame
        return $this->frameRepository->completeCurrent($stopTime);
    }

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
    ): FrameInterface {
        // Normalize to UTC
        $fromTime = $fromDate->utc();
        $toTime = $toDate->utc();

        // Validate date range
        if ($fromTime->gt($toTime)) {
            $fromTimeLocal = $this->timezoneFormatter->toLocal($fromTime);
            $toTimeLocal = $this->timezoneFormatter->toLocal($toTime);
            throw new InvalidTimeException(
                'Cannot add a frame where start time is after stop time. ' .
                "Start time: {$fromTimeLocal->toIso8601String()}, " .
                "Stop time: {$toTimeLocal->toIso8601String()}"
            );
        }

        // Get role (use provided role or default to user's default role, unless individual)
        if (!$isIndividual) {
            if ($role === null) {
                $role = $this->userRepository->getCurrentUserDefaultRole();
                if ($role === null) {
                    throw new \RuntimeException(
                        'No default role found. Please configure user.defaultRole.id in config.json.'
                    );
                }
            }
        } else {
            // Individual frames don't require a role
            $role = null;
        }

        // Create frame
        $frame = FrameFactory::create(
            $fromTime,
            $toTime,
            $activity,
            $isIndividual,
            $role,
            $description ?? ''
        );

        // Save frame
        $this->frameRepository->save($frame);

        return $frame;
    }

    /**
     * Cancel the current frame without saving it.
     *
     * @return FrameInterface The cancelled frame
     * @throws NoFrameStartedException If no frame is started
     */
    public function cancel(): FrameInterface
    {
        // Check if frame is started
        $currentFrame = $this->frameRepository->getCurrent();
        if ($currentFrame === null) {
            throw new NoFrameStartedException('No frame is started. Start a frame before canceling.');
        }

        // Clear the current frame
        $this->frameRepository->clearCurrent();

        return $currentFrame;
    }

    /**
     * Check if a frame is currently started.
     *
     * @return bool True if a frame is started, false otherwise
     */
    public function isStarted(): bool
    {
        return $this->frameRepository->getCurrent() !== null;
    }

    /**
     * Get the current frame, if one exists.
     *
     * @return FrameInterface|null The current frame, or null if none exists
     */
    public function getCurrent(): ?FrameInterface
    {
        return $this->frameRepository->getCurrent();
    }

    /**
     * Get the last (most recent) completed frame.
     * Frames are sorted by start time, descending.
     * Frames that end in the future are excluded.
     *
     * @return FrameInterface|null The last frame, or null if none exists
     */
    private function getLastFrame(): ?FrameInterface
    {
        $allFrames = $this->frameRepository->all();

        if (empty($allFrames)) {
            return null;
        }

        // Filter out frames that end in the future
        $now = Carbon::now()->utc();
        $validFrames = array_filter($allFrames, static function (FrameInterface $frame) use ($now): bool {
            // Only include frames that have a stop time and it's not in the future
            return $frame->stopTime !== null && !$frame->stopTime->gt($now);
        });

        if (empty($validFrames)) {
            return null;
        }

        // Sort frames by start time, descending (most recent first)
        usort($validFrames, static function (FrameInterface $a, FrameInterface $b): int {
            return $b->startTime->timestamp <=> $a->startTime->timestamp;
        });

        return $validFrames[0];
    }
}
