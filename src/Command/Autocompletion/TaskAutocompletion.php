<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Tcrawf\Zebra\Task\TaskInterface;
use Tcrawf\Zebra\Task\TaskRepositoryInterface;
use Tcrawf\Zebra\Task\TaskStatus;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class TaskAutocompletion
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TimezoneFormatter $timezoneFormatter
    ) {
    }

    /**
     * Provide task completion suggestions.
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
        $tasks = $this->taskRepository->all();

        // Filter out completed tasks by default (same as list command)
        $tasks = array_filter(
            $tasks,
            static fn(TaskInterface $task) => $task->status !== TaskStatus::Complete
        );

        // Sort: open first, then in-progress, then by due date (earliest first), then by creation date
        usort($tasks, function (TaskInterface $a, TaskInterface $b): int {
            $statusOrder = [
                TaskStatus::Open->value => 0,
                TaskStatus::InProgress->value => 1,
                TaskStatus::Complete->value => 2,
            ];

            $statusCompare = $statusOrder[$a->status->value] <=> $statusOrder[$b->status->value];
            if ($statusCompare !== 0) {
                return $statusCompare;
            }

            // Within same status, sort by due date (nulls last)
            $aDue = $a->dueAt->timestamp ?? PHP_INT_MAX;
            $bDue = $b->dueAt->timestamp ?? PHP_INT_MAX;
            $dueCompare = $aDue <=> $bDue;
            if ($dueCompare !== 0) {
                return $dueCompare;
            }

            // If due dates are equal (or both null), sort by creation date (oldest first)
            return $a->createdAt->timestamp <=> $b->createdAt->timestamp;
        });

        foreach ($tasks as $task) {
            if (str_starts_with($task->uuid, $incomplete)) {
                // Format description similar to interactive selection
                $createdAtLocal = $this->timezoneFormatter->toLocal($task->createdAt);
                $statusDisplay = strtoupper($task->status->value);
                $summary = $task->summary;

                $dueDisplay = 'No due date';
                if ($task->dueAt !== null) {
                    $dueAtLocal = $this->timezoneFormatter->toLocal($task->dueAt);
                    $dueDisplay = $dueAtLocal->format('Y-m-d H:i');
                }

                $activityDisplay = $task->activity !== null ? $task->activity->name : 'No activity';
                $issueTagsDisplay = !empty($task->issueTags) ? implode(', ', $task->issueTags) : 'No tags';

                $descriptionText = sprintf(
                    '%s | %s | %s | Due: %s | %s | Tags: %s',
                    $task->uuid,
                    $statusDisplay,
                    $summary,
                    $dueDisplay,
                    $activityDisplay,
                    $issueTagsDisplay
                );

                // Use Suggestion object with description
                // Note: Bash completion may only show the value, but other shells (zsh, fish) will show descriptions
                $suggestions->suggestValue(
                    new Suggestion($task->uuid, $descriptionText)
                );
            }
        }
    }
}
