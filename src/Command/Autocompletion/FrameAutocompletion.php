<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class FrameAutocompletion
{
    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly TimezoneFormatter $timezoneFormatter
    ) {
    }

    /**
     * Provide frame completion suggestions.
     *
     * @param CompletionInput $input
     * @param CompletionSuggestions $suggestions
     * @return void
     */
    public function suggest(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $incomplete = $input->getCompletionValue();

        // Check if it's a negative index pattern
        if (preg_match('/^-?\d*$/', $incomplete)) {
            // Suggest common negative indices
            for ($i = 1; $i <= 10; $i++) {
                $suggestions->suggestValue('-' . $i);
            }
        }

        // Also suggest UUIDs with descriptions
        $frames = $this->frameRepository->all();

        // Sort by start time, descending (most recent first)
        usort($frames, static fn($a, $b) => $b->startTime->timestamp <=> $a->startTime->timestamp);

        foreach ($frames as $frame) {
            if (str_starts_with($frame->uuid, $incomplete)) {
                // Format description similar to interactive selection
                $startLocal = $this->timezoneFormatter->toLocal($frame->startTime);
                $stopLocal = $frame->stopTime !== null
                    ? $this->timezoneFormatter->toLocal($frame->stopTime)
                    : null;

                $timeFrame = sprintf(
                    '%s - %s',
                    $startLocal->format('H:i'),
                    $stopLocal?->format('H:i') ?? '--:--'
                );

                $duration = $frame->getDuration();
                $durationFormatted = $duration !== null
                    ? $this->formatDuration($duration)
                    : 'N/A';

                $activityDisplay = $frame->activity->alias ?? $frame->activity->name;
                $description = $frame->description !== '' ? $frame->description : '(no description)';

                $descriptionText = sprintf(
                    '%s | %s | %s | %s | %s',
                    $frame->uuid,
                    $timeFrame,
                    $durationFormatted,
                    $activityDisplay,
                    $description
                );

                // Use Suggestion object with description
                // Note: Bash completion may only show the value, but other shells (zsh, fish) will show descriptions
                $suggestions->suggestValue(
                    new Suggestion($frame->uuid, $descriptionText)
                );
            }
        }
    }

    /**
     * Format duration in HH:MM:SS format.
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
