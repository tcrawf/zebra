<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Task;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Task\TaskInterface;
use Tcrawf\Zebra\Task\TaskRepositoryInterface;
use Tcrawf\Zebra\Task\TaskStatus;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class ListCommand extends Command
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TimezoneFormatter $timezoneFormatter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('task:list')
            ->setAliases(['tasks'])
            ->setDescription('List all tasks')
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                'Filter by status (open, in-progress, complete). If not specified, only non-completed tasks are shown.'
            )
            ->addOption(
                'issue-tag',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by issue tag (can be specified multiple times)'
            )
            ->addOption(
                'due-before',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by due date before (ISO 8601 format)'
            )
            ->addOption(
                'due-after',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by due date after (ISO 8601 format)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse filters
        $status = null;
        $statusStr = $input->getOption('status');
        if ($statusStr !== null) {
            try {
                $status = TaskStatus::from($statusStr);
            } catch (\ValueError $e) {
                $io->error("Invalid status '{$statusStr}'. Valid values: open, in-progress, complete");
                return Command::FAILURE;
            }
        }

        $issueTags = $input->getOption('issue-tag');
        $issueTags = !empty($issueTags) ? $issueTags : null;

        $dueBefore = $input->getOption('due-before');
        $dueAfter = $input->getOption('due-after');

        // Filter tasks
        $tasks = $this->taskRepository->filter(
            $status,
            $issueTags,
            $dueBefore,
            $dueAfter
        );

        // If no status filter was specified, exclude completed tasks by default
        if ($status === null) {
            $tasks = array_filter(
                $tasks,
                static fn(TaskInterface $task) => $task->status !== TaskStatus::Complete
            );
        }

        if (empty($tasks)) {
            $io->info('No tasks found.');
            return Command::SUCCESS;
        }

        // Sort tasks: open first, then in-progress, then completed
        // Within each status, sort by due date (earliest first), then by creation date
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
            $aDue = $a->dueAt?->timestamp ?? PHP_INT_MAX;
            $bDue = $b->dueAt?->timestamp ?? PHP_INT_MAX;
            $dueCompare = $aDue <=> $bDue;
            if ($dueCompare !== 0) {
                return $dueCompare;
            }

            // If due dates are equal (or both null), sort by creation date (oldest first)
            return $a->createdAt->timestamp <=> $b->createdAt->timestamp;
        });

        // Format and display tasks as table
        $tableLines = $this->formatTasksAsTable($tasks);
        foreach ($tableLines as $line) {
            $io->writeln($line);
        }

        return Command::SUCCESS;
    }

    /**
     * Format tasks as table with columns: UUID, Status, Summary, Due Date, Activity, Issue Tags.
     *
     * @param array<TaskInterface> $tasks
     * @return array<string>
     */
    private function formatTasksAsTable(array $tasks): array
    {
        if (empty($tasks)) {
            return [];
        }

        $rows = [];
        foreach ($tasks as $task) {
            $statusDisplay = strtoupper($task->status->value);
            $summary = $task->summary;

            // Format due date
            $dueDisplay = '-';
            if ($task->dueAt !== null) {
                $dueAtLocal = $this->timezoneFormatter->toLocal($task->dueAt);
                $now = Carbon::now();
                $dueAtLocalCarbon = Carbon::parse($dueAtLocal->toDateTimeString());
                $isOverdue = $task->status !== TaskStatus::Complete && $dueAtLocalCarbon->lt($now);
                $dueDisplay = $dueAtLocal->format('Y-m-d H:i');
                if ($isOverdue) {
                    $dueDisplay = '<fg=red>' . $dueDisplay . '</fg=red>';
                }
            }

            // Format activity
            $activityDisplay = $task->activity !== null ? $task->activity->name : '-';

            // Format issue tags
            $issueTagsDisplay = !empty($task->issueTags) ? implode(', ', $task->issueTags) : '-';

            $rows[] = [
                'uuid' => $task->uuid,
                'status' => $statusDisplay,
                'summary' => $summary,
                'due' => $dueDisplay,
                'activity' => $activityDisplay,
                'issueTags' => $issueTagsDisplay,
            ];
        }

        return $this->formatSimpleTable($rows);
    }

    /**
     * Format rows as a simple table.
     * Renders to a buffer and returns as array of strings for pager compatibility.
     * Wraps summary field at 50 characters.
     *
     * @param array<array<string, string>> $rows
     * @return array<string>
     */
    private function formatSimpleTable(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $bufferedOutput = new BufferedOutput();
        $table = new Table($bufferedOutput);
        $table->setHeaders(['UUID', 'Status', 'Summary', 'Due Date', 'Activity', 'Issue Tags']);

        $summaryWidth = 50;

        foreach ($rows as $row) {
            // Wrap summary
            $summaryLines = $this->wrapDescription($row['summary'], $summaryWidth);

            // Create rows for each summary line
            foreach ($summaryLines as $lineIndex => $summaryLine) {
                if ($lineIndex === 0) {
                    // First line includes all other columns
                    $table->addRow([
                        $row['uuid'],
                        $row['status'],
                        $summaryLine,
                        $row['due'],
                        $row['activity'],
                        $row['issueTags'],
                    ]);
                } else {
                    // Subsequent lines only show summary (other columns empty)
                    $table->addRow([
                        '',
                        '',
                        $summaryLine,
                        '',
                        '',
                        '',
                    ]);
                }
            }
        }

        $table->setColumnMaxWidth(2, $summaryWidth);
        $table->render();

        $renderedTable = $bufferedOutput->fetch();
        return explode("\n", rtrim($renderedTable, "\n"));
    }

    /**
     * Wrap description text at specified width, trying to break on words.
     *
     * @param string $text
     * @param int $width
     * @return array<string>
     */
    private function wrapDescription(string $text, int $width): array
    {
        if (mb_strlen($text) <= $width) {
            return [$text];
        }

        $lines = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $width) {
            // Try to find a word boundary (space, comma, etc.) before the width limit
            $breakPos = $width;
            $foundBreak = false;

            // Look for word boundaries (space, comma, semicolon, period) going backwards from width
            for ($i = min($width, mb_strlen($remaining)); $i > 0; $i--) {
                $char = mb_substr($remaining, $i - 1, 1);
                if (in_array($char, [' ', ',', ';', '.', ':', '!', '?'], true)) {
                    $breakPos = $i;
                    $foundBreak = true;
                    break;
                }
            }

            if ($foundBreak) {
                $lines[] = mb_substr($remaining, 0, $breakPos);
                $remaining = ltrim(mb_substr($remaining, $breakPos));
            } else {
                // No word boundary found, break at width
                $lines[] = mb_substr($remaining, 0, $width);
                $remaining = mb_substr($remaining, $width);
            }
        }

        if (mb_strlen($remaining) > 0) {
            $lines[] = $remaining;
        }

        return $lines;
    }
}
