<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;

class TimesheetAutocompletion
{
    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $timesheetRepository
    ) {
    }

    /**
     * Provide timesheet UUID completion suggestions.
     *
     * @param CompletionInput $input
     * @param CompletionSuggestions $suggestions
     * @return void
     */
    public function suggest(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $incomplete = $input->getCompletionValue();

        // Get all timesheets
        $timesheets = $this->timesheetRepository->all();

        // Sort by date (descending) and then by time (descending) - most recent first
        usort($timesheets, static function ($a, $b) {
            $dateCompare = $b->date->timestamp <=> $a->date->timestamp;
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return $b->time <=> $a->time;
        });

        foreach ($timesheets as $timesheet) {
            if (str_starts_with($timesheet->uuid, $incomplete)) {
                // Format description with date, activity, time, and description
                $activityDisplay = $timesheet->activity->alias ?? $timesheet->activity->name;
                $timeFormatted = sprintf('%.2f', $timesheet->time);
                $dateFormatted = $timesheet->date->format('Y-m-d');
                $description = $timesheet->description !== '' ? $timesheet->description : '(no description)';

                // Truncate description if too long
                if (mb_strlen($description) > 40) {
                    $description = mb_substr($description, 0, 37) . '...';
                }

                $descriptionText = sprintf(
                    '%s | %s | %s | %s | %s',
                    $timesheet->uuid,
                    $dateFormatted,
                    $timeFormatted . 'h',
                    $activityDisplay,
                    $description
                );

                // Use Suggestion object with description
                // Note: Bash completion may only show the value, but other shells (zsh, fish) will show descriptions
                $suggestions->suggestValue(
                    new Suggestion($timesheet->uuid, $descriptionText)
                );
            }
        }
    }
}
