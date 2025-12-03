<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\ProjectAutocompletion;
use Tcrawf\Zebra\Command\Trait\CommandInputParserTrait;
use Tcrawf\Zebra\Command\Trait\DateRangeParserTrait;
use Tcrawf\Zebra\Command\Trait\FormattingTrait;
use Tcrawf\Zebra\Command\Trait\PagerTrait;
use Tcrawf\Zebra\Command\Trait\ProjectNameHelperTrait;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class LogCommand extends Command
{
    use CommandInputParserTrait;
    use DateRangeParserTrait;
    use FormattingTrait;
    use PagerTrait;
    use ProjectNameHelperTrait;

    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly TimezoneFormatter $timezoneFormatter,
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly ProjectAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('log')
            ->setDescription('Display each recorded session during the given timespan')
            ->addOption('from', 'f', InputOption::VALUE_OPTIONAL, 'Start date (ISO 8601 format)', null)
            ->addOption('to', 't', InputOption::VALUE_OPTIONAL, 'End date (ISO 8601 format)', null)
            ->addOption('day', 'd', InputOption::VALUE_NONE, 'Current day')
            ->addOption('week', 'w', InputOption::VALUE_NONE, 'Current week')
            ->addOption('month', 'm', InputOption::VALUE_NONE, 'Current month')
            ->addOption('year', 'y', InputOption::VALUE_NONE, 'Current year')
            ->addOption(
                'project',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by project IDs'
            )
            ->addOption(
                'ignore-project',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude project IDs'
            )
            ->addOption(
                'issue-key',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by issue keys'
            )
            ->addOption(
                'ignore-issue-key',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude issue keys'
            )
            ->addOption('current', null, InputOption::VALUE_NONE, 'Include current frame')
            ->addOption('no-current', null, InputOption::VALUE_NONE, 'Do not include current frame')
            ->addOption('local', 'l', InputOption::VALUE_NONE, 'Show only frames from local projects')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Reverse order')
            ->addOption('no-reverse', null, InputOption::VALUE_NONE, 'Do not reverse order')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Format output as JSON')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Format output as CSV')
            ->addOption('pager', null, InputOption::VALUE_NONE, 'Use pager for output')
            ->addOption('no-pager', null, InputOption::VALUE_NONE, 'Do not use pager');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Parse date range
            [$from, $to] = $this->parseDateRange($input);

            // Get filter options
            $projectOptions = $input->getOption('project');
            $ignoreProjectOptions = $input->getOption('ignore-project');
            $issueKeys = $input->getOption('issue-key');
            $ignoreIssueKeys = $input->getOption('ignore-issue-key');
            $includeCurrent = $this->shouldIncludeCurrent($input);
            $reverse = $this->shouldReverse($input);

            // Resolve project identifiers to entity keys
            $projectEntityKeys = $this->resolveProjectIdentifiers($projectOptions, $io);
            $ignoreProjectEntityKeys = $this->resolveProjectIdentifiers($ignoreProjectOptions, $io);

            // Separate Zebra project IDs and local project entity keys
            $zebraProjectIds = [];
            $localProjectKeys = [];
            foreach ($projectEntityKeys ?? [] as $entityKey) {
                if ($entityKey->source === \Tcrawf\Zebra\EntityKey\EntitySource::Zebra && is_int($entityKey->id)) {
                    $zebraProjectIds[] = $entityKey->id;
                } else {
                    $localProjectKeys[] = $entityKey;
                }
            }

            $ignoreZebraProjectIds = [];
            $ignoreLocalProjectKeys = [];
            foreach ($ignoreProjectEntityKeys ?? [] as $entityKey) {
                if ($entityKey->source === \Tcrawf\Zebra\EntityKey\EntitySource::Zebra && is_int($entityKey->id)) {
                    $ignoreZebraProjectIds[] = $entityKey->id;
                } else {
                    $ignoreLocalProjectKeys[] = $entityKey;
                }
            }

            // Get frames (filter by Zebra project IDs)
            $frames = $this->frameRepository->filter(
                !empty($zebraProjectIds) ? $zebraProjectIds : null,
                $issueKeys,
                !empty($ignoreZebraProjectIds) ? $ignoreZebraProjectIds : null,
                $ignoreIssueKeys,
                $from,
                $to,
                true // include partial frames
            );

            // Filter by local project entity keys if specified
            if (!empty($localProjectKeys) || !empty($ignoreLocalProjectKeys)) {
                $frames = array_filter($frames, function ($frame) use ($localProjectKeys, $ignoreLocalProjectKeys) {
                    $frameProjectKey = $frame->activity->projectEntityKey;

                    // Filter by local project keys
                    if (!empty($localProjectKeys)) {
                        $matches = false;
                        foreach ($localProjectKeys as $projectKey) {
                            if (
                                $frameProjectKey->source === $projectKey->source
                                && $frameProjectKey->toString() === $projectKey->toString()
                            ) {
                                $matches = true;
                                break;
                            }
                        }
                        if (!$matches) {
                            return false;
                        }
                    }

                    // Ignore local project keys
                    if (!empty($ignoreLocalProjectKeys)) {
                        foreach ($ignoreLocalProjectKeys as $projectKey) {
                            if (
                                $frameProjectKey->source === $projectKey->source
                                && $frameProjectKey->toString() === $projectKey->toString()
                            ) {
                                return false;
                            }
                        }
                    }

                    return true;
                });
            }

            // Add current frame if requested
            if ($includeCurrent) {
                $currentFrame = $this->frameRepository->getCurrent();
                if ($currentFrame !== null) {
                    $frames[] = $currentFrame;
                }
            }

            // Filter to local projects only if --local option is set
            $localOnly = $input->getOption('local');
            if ($localOnly) {
                $frames = array_filter(
                    $frames,
                    static fn(FrameInterface $frame) => $frame->activity->projectEntityKey->source
                        === EntitySource::Local
                );
            }

            // Format output
            $outputFormat = $this->getOutputFormat($input);
            $outputContent = $this->formatOutput($frames, $from, $to, $outputFormat, $reverse);

            // Display output
            $usePager = $this->shouldUsePager($input);
            if ($usePager && $outputFormat === 'plain') {
                try {
                    $this->displayViaPager($outputContent, $output);
                } catch (\Exception $e) {
                    // If pager fails, fall back to direct output
                    $io->writeln($outputContent);
                }
            } else {
                $io->writeln($outputContent);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        }
    }

    /**
     * Override date range parsing for LogCommand-specific behavior.
     * LogCommand uses addDay()->startOfDay() for end dates and parses dates in UTC.
     */
    protected function getYearEndDate(Carbon $now): Carbon
    {
        return $now->copy()->addYear()->startOfYear();
    }

    protected function getMonthEndDate(Carbon $now): Carbon
    {
        return $now->copy()->addMonth()->startOfMonth();
    }

    protected function getWeekEndDate(Carbon $now): Carbon
    {
        return $now->copy()->addWeek()->startOfWeek();
    }

    protected function getDayEndDate(Carbon $now): Carbon
    {
        return $now->copy()->addDay()->startOfDay();
    }

    protected function getDefaultToDate(Carbon $now): Carbon
    {
        return $now->copy()->addDay()->startOfDay();
    }

    protected function shouldParseDatesInUtc(): bool
    {
        return true;
    }

    /**
     * Override default reverse order behavior.
     * LogCommand defaults to chronological order (oldest first).
     */
    protected function getDefaultReverseOrder(): bool
    {
        return false;
    }

    /**
     * Format output based on format type.
     *
     * @param array<FrameInterface> $frames
     * @param Carbon $from
     * @param Carbon $to
     * @param string $format
     * @param bool $reverse
     * @return string
     */
    private function formatOutput(array $frames, Carbon $from, Carbon $to, string $format, bool $reverse): string
    {
        // Sort frames by start time
        usort($frames, static fn($a, $b) => $a->startTime->timestamp <=> $b->startTime->timestamp);

        if ($reverse) {
            $frames = array_reverse($frames);
        }

        return match ($format) {
            'json' => $this->formatJson($frames),
            'csv' => $this->formatCsv($frames),
            default => $this->formatPlainText($frames, $reverse),
        };
    }

    /**
     * Format frames as JSON.
     *
     * @param array<FrameInterface> $frames
     * @return string
     */
    private function formatJson(array $frames): string
    {
        $data = array_map(function ($frame) {
            $startLocal = $this->timezoneFormatter->toLocal($frame->startTime);
            $stopLocal = $frame->stopTime !== null
                ? $this->timezoneFormatter->toLocal($frame->stopTime)
                : null;

            return [
                'id' => $frame->uuid,
                'start' => $startLocal->toIso8601String(),
                'stop' => $stopLocal?->toIso8601String(),
                'project' => $this->getProjectName($frame),
                'activity' => $frame->activity->name,
                'issue_keys' => $frame->issueKeys,
                'duration' => $frame->getDuration(),
            ];
        }, $frames);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Format frames as CSV.
     *
     * @param array<FrameInterface> $frames
     * @return string
     */
    private function formatCsv(array $frames): string
    {
        $lines = [];
        $lines[] = 'id,start,stop,project,activity,issue_keys';

        foreach ($frames as $frame) {
            $startLocal = $this->timezoneFormatter->toLocal($frame->startTime);
            $stopLocal = $frame->stopTime !== null
                ? $this->timezoneFormatter->toLocal($frame->stopTime)
                : null;

            $lines[] = sprintf(
                '%s,%s,%s,%s,%s,%s',
                $frame->uuid,
                $startLocal->toIso8601String(),
                $stopLocal?->toIso8601String() ?? '',
                $this->escapeCsv($this->getProjectName($frame)),
                $this->escapeCsv($frame->activity->name),
                $this->escapeCsv(implode(', ', $frame->issueKeys))
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Format frames as plain text.
     *
     * @param array<FrameInterface> $frames
     * @param bool $reverse
     * @return string
     */
    private function formatPlainText(array $frames, bool $reverse): string
    {
        if (empty($frames)) {
            return 'No frames found.';
        }

        // Group by day
        $framesByDay = [];
        foreach ($frames as $frame) {
            $day = $this->timezoneFormatter->toLocal($frame->startTime)->startOfDay();
            $dayKey = $day->format('Y-m-d');

            if (!isset($framesByDay[$dayKey])) {
                $framesByDay[$dayKey] = [
                    'day' => $day,
                    'frames' => [],
                ];
            }

            $framesByDay[$dayKey]['frames'][] = $frame;
        }

        // Sort days - reverse order if $reverse is true
        if ($reverse) {
            krsort($framesByDay);
        } else {
            ksort($framesByDay);
        }

        $lines = [];
        foreach ($framesByDay as $dayData) {
            $day = $dayData['day'];
            $dayFrames = $dayData['frames'];

            // Calculate daily total
            $dailyTotal = 0;
            foreach ($dayFrames as $frame) {
                $duration = $frame->getDuration();
                if ($duration !== null) {
                    $dailyTotal += $duration;
                }
            }

            $dailyTotalFormatted = $this->formatDurationWithoutSeconds($dailyTotal);
            $lines[] = sprintf('%s (%s)', $day->format('l d F Y'), $dailyTotalFormatted);

            // Sort frames within day by start time - always chronological (oldest first)
            usort($dayFrames, static fn($a, $b) => $a->startTime->timestamp <=> $b->startTime->timestamp);

            // Format frames in table format
            $dayLines = $this->formatFramesAsTable($dayFrames);
            $lines = array_merge($lines, $dayLines);

            $lines[] = ''; // Empty line between days
        }

        return implode("\n", $lines);
    }

    /**
     * Get project repository instance.
     */
    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
    }

    /**
     * Get activity display name for log output.
     * Uses activity alias if available, otherwise shows activity name.
     *
     * @param FrameInterface $frame
     * @return string
     */
    private function getActivityDisplay(FrameInterface $frame): string
    {
        $activityAlias = $frame->activity->alias ?? '';
        if ($activityAlias !== '') {
            return $activityAlias;
        }

        return $frame->activity->name;
    }

    /**
     * Format frames as table with columns: UUID, Time frame, Duration, Activity, Description.
     *
     * @param array<FrameInterface> $frames
     * @return array<string>
     */
    private function formatFramesAsTable(array $frames): array
    {
        if (empty($frames)) {
            return [];
        }

        $rows = [];
        foreach ($frames as $frame) {
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
                ? $this->formatDurationWithoutSeconds($duration)
                : 'N/A';

            $activityDisplay = $this->getActivityDisplay($frame);
            $description = $frame->description !== '' ? $frame->description : '';

            $rows[] = [
                'uuid' => $frame->uuid,
                'timeFrame' => $timeFrame,
                'duration' => $durationFormatted,
                'activity' => $activityDisplay,
                'description' => $description,
            ];
        }

        return $this->formatSimpleTable($rows);
    }

    /**
     * Format rows as a simple table (same style as RolesCommand).
     * Renders to a buffer and returns as array of strings for pager compatibility.
     * Wraps description field at 50 characters.
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
        $table->setHeaders(['UUID', 'Time frame', 'Duration', 'Activity', 'Description']);

        $descriptionWidth = 50;

        foreach ($rows as $row) {
            // Wrap description
            $descriptionLines = $this->wrapDescription($row['description'], $descriptionWidth);

            // Create rows for each description line
            foreach ($descriptionLines as $lineIndex => $descriptionLine) {
                if ($lineIndex === 0) {
                    // First line includes all other columns
                    $table->addRow([
                        $row['uuid'],
                        $row['timeFrame'],
                        $row['duration'],
                        $row['activity'],
                        $descriptionLine,
                    ]);
                } else {
                    // Subsequent lines only show description (other columns empty)
                    $table->addRow([
                        '',
                        '',
                        '',
                        '',
                        $descriptionLine,
                    ]);
                }
            }
        }

        $table->setColumnMaxWidth(4, $descriptionWidth);
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

            // Extract line up to break point
            $line = mb_substr($remaining, 0, $breakPos);
            $remaining = mb_substr($remaining, $breakPos);

            // Trim leading whitespace from remaining text
            $remaining = ltrim($remaining);

            $lines[] = $line;
        }

        // Add remaining text if any
        if (mb_strlen($remaining) > 0) {
            $lines[] = $remaining;
        }

        return $lines;
    }

    /**
     * Resolve project identifiers (names, UUIDs, or IDs) to entity keys.
     *
     * @param array<string> $identifiers
     * @param SymfonyStyle $io
     * @return array<EntityKeyInterface>|null
     */
    private function resolveProjectIdentifiers(array $identifiers, SymfonyStyle $io): ?array
    {
        if (empty($identifiers)) {
            return null;
        }

        $entityKeys = [];
        foreach ($identifiers as $identifier) {
            $entityKey = $this->resolveProjectIdentifier($identifier, $io);
            if ($entityKey !== null) {
                $entityKeys[] = $entityKey;
            }
        }

        return !empty($entityKeys) ? $entityKeys : null;
    }

    /**
     * Resolve a single project identifier to an entity key.
     *
     * @param string $identifier Project name, UUID, or ID
     * @param SymfonyStyle $io
     * @return EntityKeyInterface|null
     */
    private function resolveProjectIdentifier(string $identifier, SymfonyStyle $io): ?EntityKeyInterface
    {
        // Try as UUID first (for local projects)
        if (strlen($identifier) === 8 && ctype_xdigit($identifier) && !ctype_digit($identifier)) {
            try {
                $entityKey = EntityKey::local($identifier);
                $project = $this->projectRepository->get($entityKey);
                if ($project !== null) {
                    return $entityKey;
                }
            } catch (\InvalidArgumentException $e) {
                // Not a valid UUID, continue
            }
        }

        // Try as integer ID (for Zebra projects)
        if (ctype_digit($identifier)) {
            $entityKey = EntityKey::zebra((int) $identifier);
            $project = $this->projectRepository->get($entityKey);
            if ($project !== null) {
                return $entityKey;
            }
        }

        // Try by name
        $projects = $this->projectRepository->getByNameLike($identifier);
        if (!empty($projects)) {
            // If exact match found, return its entity key
            foreach ($projects as $project) {
                if (strcasecmp(trim($project->name), trim($identifier)) === 0) {
                    return $project->entityKey;
                }
            }
            // If single match, return its entity key
            if (count($projects) === 1) {
                return reset($projects)->entityKey;
            }
        }

        return null;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        // Handle both long and short option names for project
        if ($input->mustSuggestOptionValuesFor('project') || $input->mustSuggestOptionValuesFor('p')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
        // Handle ignore-project option
        if ($input->mustSuggestOptionValuesFor('ignore-project')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}
