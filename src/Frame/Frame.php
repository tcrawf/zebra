<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Pure data entity for time tracking frames.
 * Stores CarbonInterface objects for time values, preserving timezone information.
 */
class Frame implements FrameInterface
{
    public string $uuid;
    public CarbonInterface $startTime {
        get {
            return $this->startTime->copy();
        }
    }
    public CarbonInterface|null $stopTime {
        get {
            return $this->stopTime?->copy();
        }
    }
    public array $issueKeys;
    private CarbonInterface $updatedAtValue;
    public CarbonInterface $updatedAt {
        get {
            return $this->updatedAtValue->copy();
        }
    }
    public EntityKeyInterface $activityKey;
    private ?ActivityInterface $activityCache = null;
    private ?ActivityRepositoryInterface $activityRepository = null;
    public int|null $roleId;
    private ?RoleInterface $roleCache = null;
    private ?UserRepositoryInterface $userRepository = null;

    public ActivityInterface $activity {
        get {
            // If repository is available, try to load fresh activity
    if ($this->activityRepository !== null) {
        $loadedActivity = $this->activityRepository->get($this->activityKey);
        if ($loadedActivity !== null) {
            $this->activityCache = $loadedActivity;
            return $loadedActivity;
        }
    }
            // Otherwise return cached activity (set during construction)
    if ($this->activityCache === null) {
        throw new \RuntimeException(
            'Activity not available. Frame was loaded without ActivityRepository.'
        );
    }
            return $this->activityCache;
        }
    }

    public RoleInterface|null $role {
        get {
            // If no role ID, return null
    if ($this->roleId === null) {
        return null;
    }

            // If repository is available, try to load fresh role
    if ($this->userRepository !== null) {
        $loadedRole = $this->userRepository->getCurrentUserRoleById($this->roleId);
        if ($loadedRole !== null) {
            $this->roleCache = $loadedRole;
            return $loadedRole;
        }
    }
            // Otherwise return cached role (set during construction)
    if ($this->roleCache === null) {
        throw new \RuntimeException(
            'Role not available. Frame was loaded without UserRepository.'
        );
    }
            return $this->roleCache;
        }
    }

    public function __construct(
        UuidInterface $uuid,
        CarbonInterface|int|string $startTime,
        CarbonInterface|int|string|null $stopTime,
        ActivityInterface|EntityKeyInterface $activityOrKey,
        public bool $isIndividual,
        RoleInterface|int|null $roleOrId,
        public string $description = '',
        CarbonInterface|int|string|null $updatedAt = null,
        ?ActivityRepositoryInterface $activityRepository = null,
        ?UserRepositoryInterface $userRepository = null
    ) {
        // Handle activity parameter: can be ActivityInterface (for new frames)
        // or EntityKeyInterface (for loaded frames)
        if ($activityOrKey instanceof ActivityInterface) {
            $this->activityKey = $activityOrKey->entityKey;
            $this->activityCache = $activityOrKey;
        } else {
            $this->activityKey = $activityOrKey;
            $this->activityRepository = $activityRepository;
            // Load activity for validation if repository is available
            if ($activityRepository !== null) {
                $loadedActivity = $activityRepository->get($this->activityKey);
                if ($loadedActivity !== null) {
                    $this->activityCache = $loadedActivity;
                } else {
                    // Create placeholder activity for missing activities
                    // Use a dummy project key matching the activity source
                    $dummyProjectKey = match ($this->activityKey->source) {
                        EntitySource::Local => EntityKey::local(Uuid::random()),
                        EntitySource::Zebra => EntityKey::zebra(0),
                    };
                    $this->activityCache = new Activity(
                        $this->activityKey,
                        "Deleted Activity (ID: {$this->activityKey->toString()})",
                        '',
                        $dummyProjectKey,
                        null,
                        false
                    );
                }
            }
        }

        // Handle role parameter: can be RoleInterface (for new frames) or int (role ID for loaded frames)
        if ($roleOrId instanceof RoleInterface) {
            $this->roleId = $roleOrId->id;
            $this->roleCache = $roleOrId;
            $this->userRepository = $userRepository;
        } elseif (is_int($roleOrId)) {
            $this->roleId = $roleOrId;
            $this->userRepository = $userRepository;
            // Load role for validation if repository is available
            if ($userRepository !== null) {
                $loadedRole = $userRepository->getCurrentUserRoleById($this->roleId);
                if ($loadedRole !== null) {
                    $this->roleCache = $loadedRole;
                }
            }
        } else {
            $this->roleId = null;
            $this->roleCache = null;
        }

        // Use cached activity for validation
        $activity = $this->activityCache;
        if ($activity === null) {
            throw new InvalidArgumentException(
                'Activity is required for frame validation. ' .
                'Either provide ActivityInterface or ActivityRepositoryInterface with EntityKeyInterface.'
            );
        }

        // Use cached role for validation (if not individual)
        $role = $this->roleCache;

        // Validate: if activity requires role and not individual, role must be provided
        // Check this BEFORE the general role validation so we get the right error message
        if ($activity->roleRequired && !$isIndividual && $this->roleId === null) {
            throw new InvalidArgumentException(
                'Activity requires a role. ' .
                'Either provide a role or mark the frame as individual.'
            );
        }

        // Validate: if not individual, role must be provided
        if (!$isIndividual && $this->roleId === null) {
            throw new InvalidArgumentException(
                'Frame must have either a role or be marked as individual. ' .
                'If isIndividual is false, role cannot be null.'
            );
        }

        // Validate: if individual, role should be null
        if ($isIndividual && $this->roleId !== null) {
            throw new InvalidArgumentException(
                'Individual frames cannot have a role. ' .
                'If isIndividual is true, role must be null.'
            );
        }

        // Store UUID hex value
        $this->uuid = $uuid->getHex();

        // Convert and store startTime as CarbonInterface (normalize to UTC)
        $this->startTime = $this->convertToCarbon($startTime)->utc();

        // Convert and store stopTime as CarbonInterface if provided (normalize to UTC)
        $this->stopTime = $stopTime !== null ? $this->convertToCarbon($stopTime)->utc() : null;

        // Validate that stop time is not before start time
        if ($this->stopTime !== null && $this->stopTime->lt($this->startTime)) {
            throw new InvalidArgumentException(
                'Stop time must be equal to or greater than start time. ' .
                "Start: {$this->startTime->toIso8601String()}, " .
                "Stop: {$this->stopTime->toIso8601String()}"
            );
        }

        // Extract issue keys from description
        $this->issueKeys = $this->extractIssues($description);

        // Convert updatedAt to CarbonInterface, default to current time if null (normalize to UTC)
        $this->updatedAtValue = $updatedAt !== null
            ? $this->convertToCarbon($updatedAt)->utc()
            : \Carbon\Carbon::now()->utc();
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
            return \Carbon\Carbon::createFromTimestamp($value);
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
     * Extract issue keys from description.
     * Issue keys have the format: 2-6 uppercase letters, hyphen, 1-5 digits (e.g., AA-1234, ABC-12345).
     *
     * @param string $description The description to extract issue keys from
     * @return array Array of issue keys found in the description
     */
    private function extractIssues(string $description): array
    {
        if (empty($description)) {
            return [];
        }

        // Pattern: 3-6 uppercase letters, hyphen, 1-5 digits
        $pattern = '/[A-Z]{2,6}-\d{1,5}/';
        preg_match_all($pattern, $description, $matches);

        // Return unique issue keys
        // preg_match_all always populates $matches[0], even if empty
        return array_values(array_unique($matches[0]));
    }


    public function getStartTimestamp(): int
    {
        return $this->startTime->timestamp;
    }

    public function getStopTimestamp(): int|null
    {
        return $this->stopTime?->timestamp;
    }

    public function getUpdatedAtTimestamp(): int
    {
        return $this->updatedAtValue->timestamp;
    }

    public function isActive(): bool
    {
        return $this->stopTime === null;
    }

    public function getDuration(): int|null
    {
        if ($this->stopTime === null) {
            return null;
        }
        return $this->stopTime->diffInSeconds($this->startTime);
    }

    public function toArray(): array
    {
        // Normalized storage format: only store activity key and role ID
        return [
            'uuid' => $this->uuid,
            'start' => $this->startTime->timestamp,
            'stop' => $this->stopTime?->timestamp,
            'activity' => [
                'key' => [
                    'source' => $this->activityKey->source->value,
                    'id' => $this->activityKey->toString(),
                ],
            ],
            'isIndividual' => $this->isIndividual,
            'roleId' => $this->roleId,
            'issues' => $this->issueKeys,
            'desc' => $this->description,
            'updatedAt' => $this->updatedAtValue->timestamp
        ];
    }

    public function isLessThan(FrameInterface $other): bool
    {
        return $this->startTime->lt($other->startTime);
    }

    public function isLessThanOrEqual(FrameInterface $other): bool
    {
        return $this->startTime->lte($other->startTime);
    }

    public function isGreaterThan(FrameInterface $other): bool
    {
        return $this->startTime->gt($other->startTime);
    }

    public function isGreaterThanOrEqual(FrameInterface $other): bool
    {
        return $this->startTime->gte($other->startTime);
    }

    public function __toString(): string
    {
        $roleInfo = $this->isIndividual
            ? 'Individual'
            : ($this->role !== null ? $this->role->name : 'No role');
        $activity = $this->activity;
        return sprintf(
            'Frame(uuid=%s, activity=%s, projectEntityKey=%s, start=%s, stop=%s, ' .
            'role=%s, issueKeys=%s, description=%s)',
            $this->uuid,
            $activity->name,
            $activity->projectEntityKey->toString(),
            $this->startTime->toIso8601String(),
            $this->stopTime?->toIso8601String() ?? 'null',
            $roleInfo,
            implode(', ', $this->issueKeys),
            $this->description
        );
    }
}
