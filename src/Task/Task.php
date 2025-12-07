<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Pure data entity for tasks.
 * Stores CarbonInterface objects for time values, preserving timezone information.
 */
class Task implements TaskInterface
{
    public string $uuid;
    private CarbonInterface $createdAtValue;
    private CarbonInterface|null $dueAtValue = null;
    private CarbonInterface|null $completedAtValue = null;
    public CarbonInterface $createdAt {
        get {
            return $this->createdAtValue->copy();
        }
    }
    public CarbonInterface|null $dueAt {
        get {
            return $this->dueAtValue?->copy();
        }
    }
    public CarbonInterface|null $completedAt {
        get {
            return $this->completedAtValue?->copy();
        }
    }
    public array $issueTags;
    public string $summary;

    public function __construct(
        UuidInterface $uuid,
        string $summary,
        CarbonInterface|int|string $createdAt,
        CarbonInterface|int|string|null $dueAt = null,
        CarbonInterface|int|string|null $completedAt = null,
        public ActivityInterface|null $activity = null,
        array $issueTags = [],
        public TaskStatus $status = TaskStatus::Open,
        public string $completionNote = '',
        CarbonInterface|int|string|null $createdAtOverride = null
    ) {
        // Store UUID hex value
        $this->uuid = $uuid->getHex();

        // Validate summary is not empty
        if (trim($summary) === '') {
            throw new InvalidArgumentException('Task summary cannot be empty');
        }

        // Store summary
        $this->summary = $summary;

        // Convert and store createdAt as CarbonInterface (normalize to UTC)
        $this->createdAtValue = $createdAtOverride !== null
            ? $this->convertToCarbon($createdAtOverride)->utc()
            : $this->convertToCarbon($createdAt)->utc();

        // Convert and store dueAt as CarbonInterface if provided (normalize to UTC)
        $this->dueAtValue = $dueAt !== null ? $this->convertToCarbon($dueAt)->utc() : null;

        // Convert and store completedAt as CarbonInterface if provided (normalize to UTC)
        $this->completedAtValue = $completedAt !== null ? $this->convertToCarbon($completedAt)->utc() : null;

        // Validate status consistency
        if ($this->status === TaskStatus::Complete && $this->completedAtValue === null) {
            throw new InvalidArgumentException(
                'Task with complete status must have a completedAt datetime'
            );
        }

        if ($this->status !== TaskStatus::Complete && $this->completedAtValue !== null) {
            throw new InvalidArgumentException(
                'Task with non-complete status cannot have a completedAt datetime'
            );
        }

        // Extract issue tags from summary if not explicitly provided
        if (empty($issueTags)) {
            $this->issueTags = $this->extractIssueTags($summary);
        } else {
            $this->issueTags = $issueTags;
        }
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
     * Extract issue tags from summary.
     * Issue tags have the format: 2-6 uppercase letters, hyphen, 1-5 digits (e.g., AA-1234, ABC-12345).
     *
     * @param string $summary The summary to extract issue tags from
     * @return array Array of issue tags found in the summary
     */
    private function extractIssueTags(string $summary): array
    {
        if (empty($summary)) {
            return [];
        }

        // Pattern: 2-6 uppercase letters, hyphen, 1-5 digits
        $pattern = '/[A-Z]{2,6}-\d{1,5}/';
        preg_match_all($pattern, $summary, $matches);

        // Return unique issue tags
        // preg_match_all always populates $matches[0], even if empty
        return array_values(array_unique($matches[0]));
    }

    public function getCreatedAtTimestamp(): int
    {
        return $this->createdAtValue->timestamp;
    }

    public function getDueAtTimestamp(): int|null
    {
        return $this->dueAtValue?->timestamp;
    }

    public function getCompletedAtTimestamp(): int|null
    {
        return $this->completedAtValue?->timestamp;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'summary' => $this->summary,
            'issueTags' => $this->issueTags,
            'activity' => $this->activity !== null ? [
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
            ] : null,
            'createdAt' => $this->createdAtValue->timestamp,
            'dueAt' => $this->dueAtValue?->timestamp,
            'completedAt' => $this->completedAtValue?->timestamp,
            'status' => $this->status->value,
            'completionNote' => $this->completionNote,
        ];
    }

    public function __toString(): string
    {
        $activityInfo = $this->activity !== null ? $this->activity->name : 'No activity';
        return sprintf(
            'Task(uuid=%s, summary=%s, status=%s, activity=%s, issueTags=%s, createdAt=%s, dueAt=%s, completedAt=%s)',
            $this->uuid,
            $this->summary,
            $this->status->value,
            $activityInfo,
            implode(', ', $this->issueTags),
            $this->createdAt->toIso8601String(),
            $this->dueAt?->toIso8601String() ?? 'null',
            $this->completedAt?->toIso8601String() ?? 'null'
        );
    }
}
