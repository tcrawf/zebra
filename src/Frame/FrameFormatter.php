<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

/**
 * Handles formatting and display logic for Frame entities.
 * Separates presentation concerns from the Frame entity.
 */
class FrameFormatter
{
    private static ?TimezoneFormatter $timezoneFormatter = null;

    /**
     * Get or create the timezone formatter instance.
     */
    private static function getTimezoneFormatter(): TimezoneFormatter
    {
        if (self::$timezoneFormatter === null) {
            self::$timezoneFormatter = new TimezoneFormatter();
        }
        return self::$timezoneFormatter;
    }

    public static function formatStart(FrameInterface $frame, string|null $timezone = null): CarbonInterface
    {
        $carbon = $frame->startTime;
        if ($timezone !== null) {
            return $carbon->copy()->setTimezone($timezone);
        }
        // Default to system timezone for display
        return self::getTimezoneFormatter()->toLocal($carbon);
    }

    public static function formatStop(FrameInterface $frame, string|null $timezone = null): CarbonInterface|null
    {
        $stopTime = $frame->stopTime;
        if ($stopTime === null) {
            return null;
        }
        if ($timezone !== null) {
            return $stopTime->copy()->setTimezone($timezone);
        }
        // Default to system timezone for display
        return self::getTimezoneFormatter()->toLocal($stopTime);
    }

    public static function formatUpdatedAt(FrameInterface $frame, string|null $timezone = null): CarbonInterface
    {
        $carbon = $frame->updatedAt;
        if ($timezone !== null) {
            return $carbon->copy()->setTimezone($timezone);
        }
        // Default to system timezone for display
        return self::getTimezoneFormatter()->toLocal($carbon);
    }

    public static function getDay(FrameInterface $frame, string|null $timezone = null): CarbonInterface
    {
        return self::formatStart($frame, $timezone)->startOfDay();
    }

    public static function getDuration(FrameInterface $frame): int|null
    {
        return $frame->getDuration();
    }

    public static function getDurationFormatted(FrameInterface $frame): string|null
    {
        $duration = $frame->getDuration();
        if ($duration === null) {
            return null;
        }

        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $seconds = $duration % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    public static function formatForDisplay(FrameInterface $frame, string|null $timezone = null): array
    {
        return [
            'uuid' => $frame->uuid,
            'activity' => $frame->activity->name,
            'project_entityKey' => [
                'source' => $frame->activity->projectEntityKey->source->value,
                'id' => $frame->activity->projectEntityKey->toString(),
            ],
            'start' => self::formatStart($frame, $timezone)->toDateTimeString(),
            'stop' => $frame->getStopTimestamp()
                ? self::formatStop($frame, $timezone)->toDateTimeString()
                : null,
            'issue_keys' => $frame->issueKeys,
            'duration' => self::getDurationFormatted($frame),
            'updated_at' => self::formatUpdatedAt($frame, $timezone)->toDateTimeString()
        ];
    }
}
