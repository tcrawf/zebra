<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
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

    public function __construct(
        UuidInterface $uuid,
        CarbonInterface|int|string $startTime,
        CarbonInterface|int|string|null $stopTime,
        public Activity $activity,
        public bool $isIndividual,
        public RoleInterface|null $role,
        public string $description = '',
        CarbonInterface|int|string|null $updatedAt = null
    ) {
        // Validate: if not individual, role must be provided
        if (!$isIndividual && $role === null) {
            throw new InvalidArgumentException(
                'Frame must have either a role or be marked as individual. ' .
                'If isIndividual is false, role cannot be null.'
            );
        }

        // Validate: if individual, role should be null
        if ($isIndividual && $role !== null) {
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
        return [
            'uuid' => $this->uuid,
            'start' => $this->startTime->timestamp,
            'stop' => $this->stopTime?->timestamp,
            'activity' => [
                'key' => [
                    'source' => $this->activity->entityKey->source->value,
                    'id' => $this->activity->entityKey->toString(),
                ],
                'name' => $this->activity->name,
                'desc' => $this->activity->description,
                'project' => [
                    'source' => $this->activity->projectEntityKey->source->value,
                    'id' => $this->activity->projectEntityKey->toString(),
                ],
                'alias' => $this->activity->alias,
            ],
            'isIndividual' => $this->isIndividual,
            'role' => $this->role !== null ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ] : null,
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
        return sprintf(
            'Frame(uuid=%s, activity=%s, projectEntityKey=%s, start=%s, stop=%s, ' .
            'role=%s, issueKeys=%s, description=%s)',
            $this->uuid,
            $this->activity->name,
            $this->activity->projectEntityKey->toString(),
            $this->startTime->toIso8601String(),
            $this->stopTime?->toIso8601String() ?? 'null',
            $roleInfo,
            implode(', ', $this->issueKeys),
            $this->description
        );
    }
}
