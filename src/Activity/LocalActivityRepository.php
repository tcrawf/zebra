<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

use InvalidArgumentException;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\LocalProjectRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectStatus;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Repository for storing and retrieving local activities.
 * Activities are stored within their parent projects.
 */
class LocalActivityRepository implements LocalActivityRepositoryInterface
{
    /**
     * @param LocalProjectRepositoryInterface $projectRepository
     * @param FrameRepositoryInterface|null $frameRepository Optional frame repository for checking references
     */
    public function __construct(
        private readonly LocalProjectRepositoryInterface $projectRepository,
        private readonly ?FrameRepositoryInterface $frameRepository = null
    ) {
    }

    /**
     * Get all local activities from local projects.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     *
     * @param bool $activeOnly If true, only load active projects. Defaults to true.
     * @return array<ActivityInterface> Array of activities from matching projects
     */
    public function all(bool $activeOnly = true): array
    {
        // Load projects based on activeOnly flag
        if ($activeOnly) {
            $projects = $this->projectRepository->all([ProjectStatus::Active]);
        } else {
            $projects = $this->projectRepository->all([]);
        }

        $activities = [];

        foreach ($projects as $project) {
            foreach ($project->activities as $activity) {
                // Only include local activities
                if ($activity->entityKey->source === EntitySource::Local) {
                    $activities[] = $activity;
                }
            }
        }

        return $activities;
    }

    /**
     * Get a local activity by its entity key.
     *
     * @param EntityKeyInterface $entityKey The activity's entity key
     * @return ActivityInterface|null
     */
    public function get(EntityKeyInterface $entityKey): ?ActivityInterface
    {
        if ($entityKey->source !== EntitySource::Local) {
            return null;
        }

        if (!($entityKey->id instanceof UuidInterface)) {
            return null;
        }

        $project = $this->projectRepository->getByActivityId($entityKey);
        if ($project === null) {
            return null;
        }

        $uuidString = $entityKey->id->getHex();
        return array_find(
            $project->activities,
            static fn($activity) => $activity->entityKey->source === EntitySource::Local
                && $activity->entityKey->id instanceof UuidInterface
                && $activity->entityKey->id->getHex() === $uuidString
        );
    }

    /**
     * Get a local activity by its alias.
     * If activeOnly is true (default), only returns activities from active projects.
     * If activeOnly is false, returns activities from all projects.
     *
     * @param string $alias The activity alias to search for
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return ActivityInterface|null
     */
    public function getByAlias(string $alias, bool $activeOnly = true): ?ActivityInterface
    {
        $project = $this->projectRepository->getByActivityAlias($alias);
        if ($project === null) {
            return null;
        }

        // Only return activity if activeOnly is true and project is active (status === 1)
        if ($activeOnly && $project->status !== 1) {
            return null;
        }

        $activity = array_find(
            $project->activities,
            static fn($activity) => $activity->alias === $alias
                && $activity->entityKey->source === EntitySource::Local
        );

        return $activity;
    }

    /**
     * Search for local activities by name or alias (case-insensitive contains).
     * If activeOnly is true (default), only searches in active projects.
     * If activeOnly is false, searches in all projects.
     *
     * @param string $search The search string to match against activity names or aliases
     * @param bool $activeOnly If true, only search in active projects. Defaults to true.
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByNameOrAlias(string $search, bool $activeOnly = true): array
    {
        $activities = $this->all($activeOnly);
        $matches = [];
        $searchLower = strtolower($search);

        foreach ($activities as $activity) {
            $nameLower = strtolower($activity->name);
            $aliasLower = $activity->alias !== null ? strtolower($activity->alias) : '';

            // Match by name or alias (case-insensitive contains)
            if (str_contains($nameLower, $searchLower) || str_contains($aliasLower, $searchLower)) {
                $matches[] = $activity;
            }
        }

        return $matches;
    }

    /**
     * Search for local activities by alias only (case-insensitive contains).
     * Only returns activities from active projects (status === 1).
     *
     * @param string $search The search string to match against activity aliases
     * @return array<ActivityInterface> Array of matching activities
     */
    public function searchByAlias(string $search): array
    {
        $activities = $this->all(true);
        $matches = [];
        $searchLower = strtolower($search);

        foreach ($activities as $activity) {
            if ($activity->alias !== null) {
                $aliasLower = strtolower($activity->alias);
                // Match by alias only (case-insensitive contains)
                if (str_contains($aliasLower, $searchLower)) {
                    $matches[] = $activity;
                }
            }
        }

        return $matches;
    }

    /**
     * Create a new local activity.
     *
     * @param string $name Activity name
     * @param string $description Activity description
     * @param EntityKeyInterface $projectEntityKey The parent project's entity key
     * @param string|null $alias Optional activity alias
     * @return ActivityInterface The created activity
     */
    public function create(
        string $name,
        string $description,
        EntityKeyInterface $projectEntityKey,
        ?string $alias = null
    ): ActivityInterface {
        if ($projectEntityKey->source !== EntitySource::Local) {
            throw new InvalidArgumentException('Project entity key must be from Local source');
        }

        $project = $this->projectRepository->get($projectEntityKey);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found');
        }

        $activityUuid = Uuid::random();
        $activityEntityKey = EntityKey::local($activityUuid);

        $newActivity = new Activity(
            $activityEntityKey,
            $name,
            $description,
            $projectEntityKey,
            $alias
        );

        // Add activity to project's activities array
        $activities = $project->activities;
        $activities[] = $newActivity;

        // Update the project with new activities
        $this->projectRepository->updateActivities($projectEntityKey, $activities);

        return $newActivity;
    }

    /**
     * Update an existing local activity.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param string|null $name Optional new name
     * @param string|null $description Optional new description
     * @param string|null $alias Optional new alias
     * @return ActivityInterface The updated activity
     */
    public function update(
        UuidInterface|string $uuid,
        ?string $name = null,
        ?string $description = null,
        ?string $alias = null
    ): ActivityInterface {
        $uuidObj = $uuid instanceof UuidInterface ? $uuid : Uuid::fromHex($uuid);
        $entityKey = EntityKey::local($uuidObj);
        $activity = $this->get($entityKey);
        if ($activity === null) {
            throw new InvalidArgumentException("Activity with UUID {$uuid} not found");
        }

        $project = $this->projectRepository->get($activity->projectEntityKey);
        if ($project === null) {
            throw new InvalidArgumentException('Parent project not found');
        }

        $newName = $name ?? $activity->name;
        $newDescription = $description ?? $activity->description;
        $newAlias = $alias !== null ? $alias : $activity->alias;

        $updatedActivity = new Activity(
            $activity->entityKey,
            $newName,
            $newDescription,
            $activity->projectEntityKey,
            $newAlias
        );

        // Update activity in project's activities array
        $uuidString = $uuid instanceof UuidInterface ? $uuid->getHex() : $uuid;
        $activities = array_map(
            static fn($a) => ($a->entityKey->id instanceof UuidInterface
                && $a->entityKey->id->getHex() === $uuidString)
                ? $updatedActivity
                : $a,
            $project->activities
        );

        $this->projectRepository->updateActivities($activity->projectEntityKey, $activities);

        return $updatedActivity;
    }

    /**
     * Delete a local activity.
     * Throws an exception if the activity has frames referencing it, unless force is true.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param bool $force If true, delete even if activity has frames (cascades to frames)
     * @return void
     * @throws InvalidArgumentException If activity has frames and force is false
     */
    public function delete(UuidInterface|string $uuid, bool $force = false): void
    {
        $uuidObj = $uuid instanceof UuidInterface ? $uuid : Uuid::fromHex($uuid);
        $entityKey = EntityKey::local($uuidObj);
        $activity = $this->get($entityKey);
        if ($activity === null) {
            throw new InvalidArgumentException("Activity with UUID {$uuid} not found");
        }

        // Check if activity has frames
        if (!$force && $this->hasFrames($uuid)) {
            $uuidString = $uuid instanceof UuidInterface ? $uuid->getHex() : $uuid;
            throw new InvalidArgumentException(
                "Cannot delete activity with UUID {$uuidString}: activity has frames referencing it. " .
                "Use force delete to cascade deletion."
            );
        }

        $project = $this->projectRepository->get($activity->projectEntityKey);
        if ($project === null) {
            throw new InvalidArgumentException('Parent project not found');
        }

        $uuidString = $uuid instanceof UuidInterface ? $uuid->getHex() : $uuid;

        // Remove activity from project's activities array
        $activities = array_filter(
            $project->activities,
            static fn($a) => !($a->entityKey->id instanceof UuidInterface
                && $a->entityKey->id->getHex() === $uuidString)
        );

        $this->projectRepository->updateActivities($activity->projectEntityKey, array_values($activities));
    }

    /**
     * Check if an activity has any frames referencing it.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @return bool True if activity has frames, false otherwise
     */
    public function hasFrames(UuidInterface|string $uuid): bool
    {
        return !empty($this->getFrames($uuid));
    }

    /**
     * Get all frames referencing an activity.
     * Includes both completed frames and the current (active) frame if it exists.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @return array<\Tcrawf\Zebra\Frame\FrameInterface> Array of frames referencing the activity
     */
    public function getFrames(UuidInterface|string $uuid): array
    {
        if ($this->frameRepository === null) {
            // If frame repository is not available, return empty array (for backward compatibility)
            return [];
        }

        $uuidObj = $uuid instanceof UuidInterface ? $uuid : Uuid::fromHex($uuid);
        $entityKey = EntityKey::local($uuidObj);
        $activity = $this->get($entityKey);
        if ($activity === null) {
            return [];
        }

        $allFrames = $this->frameRepository->all();
        $activityKeyString = $entityKey->toString();
        $matchingFrames = [];

        // Check completed frames
        foreach ($allFrames as $frame) {
            $frameActivityKey = $frame->activity->entityKey;
            // Compare entityKeys: same source and same ID string
            if (
                $frameActivityKey->source === $entityKey->source
                && $frameActivityKey->toString() === $activityKeyString
            ) {
                $matchingFrames[] = $frame;
            }
        }

        // Check current (active) frame
        $currentFrame = $this->frameRepository->getCurrent();
        if ($currentFrame !== null) {
            $currentFrameActivityKey = $currentFrame->activity->entityKey;
            // Compare entityKeys: same source and same ID string
            if (
                $currentFrameActivityKey->source === $entityKey->source
                && $currentFrameActivityKey->toString() === $activityKeyString
            ) {
                // Only add if not already in the list (shouldn't happen, but be safe)
                $alreadyIncluded = false;
                foreach ($matchingFrames as $frame) {
                    if ($frame->uuid === $currentFrame->uuid) {
                        $alreadyIncluded = true;
                        break;
                    }
                }
                if (!$alreadyIncluded) {
                    $matchingFrames[] = $currentFrame;
                }
            }
        }

        return $matchingFrames;
    }

    /**
     * Force delete a local activity, cascading deletion to all frames referencing it.
     *
     * @param UuidInterface|string $uuid The activity UUID
     * @param callable $deleteFrameCallback Callback to delete frames (receives FrameInterface)
     * @return void
     */
    public function forceDelete(UuidInterface|string $uuid, callable $deleteFrameCallback): void
    {
        $uuidObj = $uuid instanceof UuidInterface ? $uuid : Uuid::fromHex($uuid);
        $entityKey = EntityKey::local($uuidObj);
        $activity = $this->get($entityKey);
        if ($activity === null) {
            $uuidString = $uuid instanceof UuidInterface ? $uuid->getHex() : $uuid;
            throw new InvalidArgumentException("Activity with UUID {$uuidString} not found");
        }

        // Delete all frames referencing this activity
        if ($this->frameRepository !== null) {
            $allFrames = $this->frameRepository->all();
            $activityKeyString = $entityKey->toString();

            // Delete completed frames
            foreach ($allFrames as $frame) {
                $frameActivityKey = $frame->activity->entityKey;
                // Compare entityKeys: same source and same ID string
                if (
                    $frameActivityKey->source === $entityKey->source
                    && $frameActivityKey->toString() === $activityKeyString
                ) {
                    $deleteFrameCallback($frame);
                }
            }

            // Delete current (active) frame if it references this activity
            $currentFrame = $this->frameRepository->getCurrent();
            if ($currentFrame !== null) {
                $currentFrameActivityKey = $currentFrame->activity->entityKey;
                // Compare entityKeys: same source and same ID string
                if (
                    $currentFrameActivityKey->source === $entityKey->source
                    && $currentFrameActivityKey->toString() === $activityKeyString
                ) {
                    $deleteFrameCallback($currentFrame);
                }
            }
        }

        // Delete the activity itself
        $this->delete($uuid, true);
    }
}
