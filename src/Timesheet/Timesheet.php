<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Pure data entity for timesheet entries.
 * Stores CarbonInterface objects for date values, preserving timezone information.
 */
class Timesheet implements TimesheetInterface
{
    public string $uuid;
    private CarbonInterface $dateValue;
    public CarbonInterface $date {
        get {
            return $this->dateValue->copy();
        }
    }
    private CarbonInterface $updatedAtValue;
    public CarbonInterface $updatedAt {
        get {
            return $this->updatedAtValue->copy();
        }
    }

    public function __construct(
        UuidInterface $uuid,
        public ActivityInterface $activity,
        public string $description,
        public string|null $clientDescription,
        public float $time,
        CarbonInterface|int|string $date,
        public RoleInterface|null $role,
        public bool $individualAction,
        public array $frameUuids,
        public int|null $zebraId = null,
        CarbonInterface|int|string|null $updatedAt = null,
        public bool $doNotSync = false
    ) {
        // Store UUID hex value
        $this->uuid = $uuid->getHex();

        // Validate that activity is from Zebra source
        if ($activity->entityKey->source !== EntitySource::Zebra) {
            throw new InvalidArgumentException(
                'Timesheet can only accept Zebra activities, got: ' . $activity->entityKey->source->value
            );
        }

        // Validate that activity's project is also from Zebra source
        if ($activity->projectEntityKey->source !== EntitySource::Zebra) {
            throw new InvalidArgumentException(
                'Timesheet activity must have a Zebra project'
            );
        }

        // Validate that activity's project ID is an integer
        if (!is_int($activity->projectEntityKey->id)) {
            throw new InvalidArgumentException(
                'Timesheet activity project ID must be an integer'
            );
        }

        // Validate time is a multiple of 0.25
        if ($time < 0) {
            throw new InvalidArgumentException(
                "Time must be non-negative, got: {$time}"
            );
        }

        $remainder = fmod($time * 100, 25);
        // Use abs() and compare with epsilon to handle floating point precision
        if (abs($remainder) > 0.0001) {
            throw new InvalidArgumentException(
                "Time must be a multiple of 0.25, got: {$time}"
            );
        }

        // Validate that either role is set or individualAction is true
        if ($role === null && !$individualAction) {
            throw new InvalidArgumentException(
                'Either role must be set or individualAction must be true'
            );
        }

        // Validate frameUuids is an array of strings
        foreach ($frameUuids as $frameUuid) {
            if (!is_string($frameUuid)) {
                throw new InvalidArgumentException(
                    'All frame UUIDs must be strings'
                );
            }
        }

        // Convert and store date as CarbonInterface in Europe/Zurich timezone (API timezone)
        // Dates represent calendar dates in Europe/Zurich, not UTC
        $this->dateValue = $this->convertDateToCarbon($date)->setTimezone('Europe/Zurich')->startOfDay();

        // Convert updatedAt to CarbonInterface, default to current time if null (normalize to UTC)
        $this->updatedAtValue = $updatedAt !== null
            ? $this->convertToCarbon($updatedAt)->utc()
            : Carbon::now()->utc();
    }

    /**
     * Convert a date value to a CarbonInterface instance in Europe/Zurich timezone.
     * Date-only strings (Y-m-d format) are parsed as Europe/Zurich dates (API timezone).
     * Other strings are parsed in the local/system timezone, then converted to Europe/Zurich.
     *
     * @param CarbonInterface|int|string $value
     * @return CarbonInterface
     */
    private function convertDateToCarbon(CarbonInterface|int|string $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->setTimezone('Europe/Zurich');
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value, 'Europe/Zurich');
        }

        // Check if it's a date-only string (Y-m-d format) - these are stored as Europe/Zurich dates
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            // Parse as Europe/Zurich date to match API timezone
            return Carbon::parse($value, 'Europe/Zurich')->startOfDay();
        }

        // Parse other strings in local timezone, then convert to Europe/Zurich
        // This ensures strings without timezone info are interpreted in user's local timezone
        static $timezoneFormatter = null;
        if ($timezoneFormatter === null) {
            $timezoneFormatter = new TimezoneFormatter();
        }
        $localParsed = $timezoneFormatter->parseLocalToUtc($value);
        return $localParsed->setTimezone('Europe/Zurich');
    }

    /**
     * Convert a time value to a CarbonInterface instance.
     * Used for timestamps (updatedAt), which are stored in UTC.
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

        // Parse strings in local timezone, then convert to UTC
        static $timezoneFormatter = null;
        if ($timezoneFormatter === null) {
            $timezoneFormatter = new TimezoneFormatter();
        }
        return $timezoneFormatter->parseLocalToUtc($value);
    }

    public function getUpdatedAtTimestamp(): int
    {
        return $this->updatedAtValue->timestamp;
    }

    public function getDateTimestamp(): int
    {
        return $this->dateValue->timestamp;
    }

    /**
     * Get the project ID from the activity's project entity key.
     */
    public function getProjectId(): int
    {
        return $this->activity->projectEntityKey->id;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'projectId' => $this->getProjectId(),
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
            'description' => $this->description,
            'clientDescription' => $this->clientDescription,
            'time' => $this->time,
            'date' => $this->dateValue->setTimezone('Europe/Zurich')->format('Y-m-d'),
            'role' => $this->role !== null ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'fullName' => $this->role->fullName,
                'type' => $this->role->type,
                'status' => $this->role->status,
            ] : null,
            'individualAction' => $this->individualAction,
            'frameUuids' => $this->frameUuids,
            'zebraId' => $this->zebraId,
            'updatedAt' => $this->updatedAtValue->timestamp,
            'doNotSync' => $this->doNotSync
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'Timesheet(uuid=%s, projectId=%d, activity=%s, date=%s, time=%.2f, zebraId=%s)',
            $this->uuid,
            $this->getProjectId(),
            $this->activity->name,
            $this->dateValue->format('Y-m-d'),
            $this->time,
            $this->zebraId !== null ? (string) $this->zebraId : 'null'
        );
    }
}
