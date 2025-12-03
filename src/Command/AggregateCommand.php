<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Trait\CommandInputParserTrait;
use Tcrawf\Zebra\Command\Trait\DateRangeParserTrait;
use Tcrawf\Zebra\Command\Trait\FormattingTrait;
use Tcrawf\Zebra\Command\Trait\PagerTrait;
use Tcrawf\Zebra\Command\Trait\ProjectNameHelperTrait;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Report\ReportServiceInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class AggregateCommand extends Command
{
    use CommandInputParserTrait;
    use DateRangeParserTrait;
    use FormattingTrait;
    use PagerTrait;
    use ProjectNameHelperTrait;

    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly ReportServiceInterface $reportService,
        private readonly TimezoneFormatter $timezoneFormatter,
        private readonly ProjectRepositoryInterface $projectRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('aggregate')
            ->setDescription('Display a report of the time spent on each project aggregated by day')
            ->addOption('from', 'f', InputOption::VALUE_OPTIONAL, 'Start date (ISO 8601 format)', null)
            ->addOption('to', 't', InputOption::VALUE_OPTIONAL, 'End date (ISO 8601 format)', null)
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
            ->addOption('json', null, InputOption::VALUE_NONE, 'Format output as JSON')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Format output as CSV')
            ->addOption(
                'by-issue-key',
                null,
                InputOption::VALUE_NONE,
                'Group by issue-key and activity (default behavior)'
            )
            ->addOption('day', 'd', InputOption::VALUE_NONE, 'Current day')
            ->addOption('yesterday', null, InputOption::VALUE_NONE, 'Aggregate time for yesterday')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Reverse chronological order (newest first)')
            ->addOption('no-reverse', null, InputOption::VALUE_NONE, 'Do not reverse order (oldest first)')
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
            $projectIds = $this->parseIntArray($input->getOption('project'));
            $ignoreProjectIds = $this->parseIntArray($input->getOption('ignore-project'));
            $issueKeys = $input->getOption('issue-key');
            $ignoreIssueKeys = $input->getOption('ignore-issue-key');
            $includeCurrent = $this->shouldIncludeCurrent($input);

            // Get frames
            $frames = $this->frameRepository->filter(
                $projectIds,
                $issueKeys,
                $ignoreProjectIds,
                $ignoreIssueKeys,
                $from,
                $to,
                true // include partial frames
            );

            // Add current frame if requested
            if ($includeCurrent) {
                $currentFrame = $this->frameRepository->getCurrent();
                if ($currentFrame !== null) {
                    $frames[] = $currentFrame;
                }
            }

            // Check grouping mode (default is by issue-key)
            $byIssueKey = true; // Default to grouping by issue-key
            $reverse = $this->shouldReverse($input);

            // Group by day and generate reports
            $dailyReports = $this->generateDailyReports($frames, $from, $to, $byIssueKey, $reverse);

            // Format output
            $outputFormat = $this->getOutputFormat($input);
            $outputContent = $this->formatOutput($dailyReports, $outputFormat, $byIssueKey, $frames);

            // Display output
            $usePager = $this->shouldUsePager($input);
            if ($usePager && $outputFormat === 'plain') {
                $this->displayViaPager($outputContent, $output);
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
     * Generate reports grouped by day.
     *
     * @param array<\Tcrawf\Zebra\Frame\FrameInterface> $frames
     * @param Carbon $from
     * @param Carbon $to
     * @param bool $byIssueKey
     * @param bool $reverse
     * @return array<array<string, mixed>>
     */
    private function generateDailyReports(
        array $frames,
        Carbon $from,
        Carbon $to,
        bool $byIssueKey = false,
        bool $reverse = false
    ): array {
        $dailyReports = [];
        // Convert to local timezone for day boundary calculations
        $fromLocal = $this->timezoneFormatter->toLocal($from);
        $toLocal = $this->timezoneFormatter->toLocal($to);
        $current = $fromLocal->copy();

        while ($current->lte($toLocal)) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();

            // Filter frames for this day (comparing local times)
            $dayFrames = array_filter($frames, function ($frame) use ($dayStart, $dayEnd) {
                $frameStart = $this->timezoneFormatter->toLocal($frame->startTime);
                return $frameStart->gte($dayStart) && $frameStart->lte($dayEnd);
            });

            if (!empty($dayFrames)) {
                // Sort frames by start time for consistent aggregation
                usort($dayFrames, static fn($a, $b) => $a->startTime->timestamp <=> $b->startTime->timestamp);

                if ($byIssueKey) {
                    $report = $this->reportService->generateReportByIssueKey(
                        $dayFrames,
                        $dayStart->utc(),
                        $dayEnd->utc()
                    );
                } else {
                    $report = $this->reportService->generateReport($dayFrames, $dayStart->utc(), $dayEnd->utc());
                }

                // Store frames with report for description display
                $report['frames'] = $dayFrames;
                $dailyReports[] = $report;
            }

            $current->addDay();
        }

        // Reverse order if requested (newest first)
        if ($reverse) {
            $dailyReports = array_reverse($dailyReports);
        }

        return $dailyReports;
    }

    /**
     * Override default reverse order behavior.
     * AggregateCommand defaults to chronological order (oldest first).
     */
    protected function getDefaultReverseOrder(): bool
    {
        return false;
    }

    /**
     * Format output based on format type.
     *
     * @param array<array<string, mixed>> $dailyReports
     * @param string $format
     * @param bool $byIssueKey
     * @param array<\Tcrawf\Zebra\Frame\FrameInterface> $allFrames
     * @return string
     */
    private function formatOutput(
        array $dailyReports,
        string $format,
        bool $byIssueKey = false,
        array $allFrames = []
    ): string {
        if ($format === 'json') {
            return json_encode($dailyReports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if ($format === 'csv') {
            if ($byIssueKey) {
                $csvLines = [];
                $csvLines[] = 'from,to,issue_key,activity,time';
                $firstReport = true;
                foreach ($dailyReports as $report) {
                    $csvContent = $this->reportService->formatCsvByIssueKey($report);
                    // Skip header line after first report
                    if ($firstReport) {
                        $csvLines[] = $csvContent;
                        $firstReport = false;
                    } else {
                        $lines = explode("\n", $csvContent);
                        // Skip first line (header) and add the rest
                        $csvLines[] = implode("\n", array_slice($lines, 1));
                    }
                }
                return implode("\n", $csvLines);
            }

            $csvLines = [];
            $csvLines[] = 'from,to,project,activity,issue_key,time';

            foreach ($dailyReports as $report) {
                $fromIso = $report['timespan']['from']->toIso8601String();
                $toIso = $report['timespan']['to']->toIso8601String();

                foreach ($report['projects'] as $project) {
                    $csvLines[] = sprintf(
                        '%s,%s,%s,,,%d',
                        $fromIso,
                        $toIso,
                        $this->escapeCsv($project['name']),
                        $project['time']
                    );

                    foreach ($project['activities'] as $activity) {
                        // Activity-level row (without issue key)
                        $csvLines[] = sprintf(
                            '%s,%s,%s,%s,,%d',
                            $fromIso,
                            $toIso,
                            $this->escapeCsv($project['name']),
                            $this->escapeCsv($activity['name']),
                            $activity['time']
                        );

                        // Issue key-level rows
                        foreach ($activity['issueKeys'] as $issueKeyEntry) {
                            $csvLines[] = sprintf(
                                '%s,%s,%s,%s,%s,%d',
                                $fromIso,
                                $toIso,
                                $this->escapeCsv($project['name']),
                                $this->escapeCsv($activity['name']),
                                $this->escapeCsv($issueKeyEntry['issueKey']),
                                $issueKeyEntry['time']
                            );
                        }
                    }
                }
            }

            return implode("\n", $csvLines);
        }

        // Plain text format
        $lines = [];
        foreach ($dailyReports as $report) {
            // Format day header with total hours in brackets (without seconds)
            $dayLocal = $this->timezoneFormatter->toLocal($report['timespan']['from']);
            $totalTime = $this->formatDurationWithoutSeconds($report['time']);
            $lines[] = sprintf('%s (%s)', $dayLocal->format('l d F Y'), $totalTime);

            // Format report with frame descriptions
            if ($byIssueKey) {
                $dayLines = $this->formatAggregateByIssueKey($report);
            } else {
                $dayLines = $this->formatAggregate($report);
            }

            $lines = array_merge($lines, $dayLines);

            // Add subtotals
            $subtotals = $this->calculateSubtotals($report['frames'] ?? []);
            $lines[] = sprintf('Local activities: %s', $this->formatDurationWithoutSeconds($subtotals['local']));
            $lines[] = sprintf('Zebra activities: %s', $this->formatDurationWithoutSeconds($subtotals['zebra']));
            $lines[] = sprintf('Total activities: %s', $this->formatDurationWithoutSeconds($subtotals['total']));

            // Empty line between days
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Calculate subtotals for local, zebra, and total activities.
     *
     * @param array<FrameInterface> $frames
     * @return array{local: int, zebra: int, total: int}
     */
    private function calculateSubtotals(array $frames): array
    {
        $localTime = 0;
        $zebraTime = 0;

        foreach ($frames as $frame) {
            $duration = $frame->getDuration();
            if ($duration === null) {
                continue; // Skip active frames
            }

            if ($this->isLocalActivity($frame)) {
                $localTime += $duration;
            } else {
                $zebraTime += $duration;
            }
        }

        return [
            'local' => $localTime,
            'zebra' => $zebraTime,
            'total' => $localTime + $zebraTime,
        ];
    }

    /**
     * Format aggregate report with frame descriptions in tabular format.
     *
     * @param array<string, mixed> $report
     * @return array<string>
     */
    private function formatAggregate(array $report): array
    {
        $rows = [];
        $frames = $report['frames'] ?? [];

        // Group frames by project/activity/issue-key for description lookup
        $framesByKey = [];
        foreach ($frames as $frame) {
            $projectKey = $frame->activity->projectEntityKey->toString();
            $activityKey = $frame->activity->entityKey->toString();
            $frameIssueKeys = $frame->issueKeys;
            if (empty($frameIssueKeys)) {
                $frameIssueKeys = ['(no issue key)'];
            }

            foreach ($frameIssueKeys as $issueKey) {
                $key = sprintf('%s|%s|%s', $projectKey, $activityKey, $issueKey);
                if (!isset($framesByKey[$key])) {
                    $framesByKey[$key] = [];
                }
                $framesByKey[$key][] = $frame;
            }
        }

        foreach ($report['projects'] as $project) {
            $projectName = $this->abbreviateProjectName($project['name']);

            foreach ($project['activities'] as $activity) {
                $activityName = $activity['name'];
                $activityAlias = $activity['alias'] ?? '';
                $activityDisplay = $activityAlias !== ''
                    ? $activityAlias
                    : $activityName;

                foreach ($activity['issueKeys'] as $issueKeyEntry) {
                    $issueKey = $issueKeyEntry['issueKey'];
                    $displayIssueKey = $issueKey === '(no issue key)' ? '-' : $issueKey;
                    $duration = $this->formatDurationWithoutSeconds($issueKeyEntry['time']);

                    // Collect and deduplicate descriptions for this issue key
                    // Use original issueKey for lookup since framesByKey uses "(no issue key)"
                    $key = sprintf(
                        '%s|%s|%s',
                        $project['entityKey']['id'],
                        $activity['entityKey']['id'],
                        $issueKey
                    );
                    $descriptions = [];
                    if (isset($framesByKey[$key])) {
                        foreach ($framesByKey[$key] as $frame) {
                            if ($frame->description !== '') {
                                $descriptions[] = $frame->description;
                            }
                        }
                    }
                    $descriptions = $this->deduplicateDescriptions($descriptions);
                    $descriptionText = implode(' ', $descriptions);

                    $rows[] = [
                        'project' => $projectName,
                        'activity' => $activityDisplay,
                        'duration' => $duration,
                        'issueKey' => $displayIssueKey,
                        'description' => $descriptionText,
                    ];
                }
            }
        }

        return $this->formatSimpleTable($rows);
    }

    /**
     * Format aggregate report by issue-key with frame descriptions in tabular format.
     * Groups by issue-key set (order-independent) and activity.
     * Multiple issue keys are displayed stacked vertically in the Issue ID column.
     *
     * @param array<string, mixed> $report
     * @return array<string>
     */
    private function formatAggregateByIssueKey(array $report): array
    {
        $frames = $report['frames'] ?? [];

        // Build a structure to map groups to frames for description lookup
        // Group key: sorted issue keys JSON + activity key
        $groupFrames = [];
        foreach ($frames as $frame) {
            $activityKey = $frame->activity->entityKey->toString();
            $frameIssueKeys = $frame->issueKeys;
            if (empty($frameIssueKeys)) {
                $frameIssueKeys = ['(no issue key)'];
            }

            // Sort issue keys to match grouping logic
            $sortedIssueKeys = $frameIssueKeys;
            sort($sortedIssueKeys);
            $groupKey = json_encode($sortedIssueKeys) . '|' . $activityKey;

            if (!isset($groupFrames[$groupKey])) {
                $groupFrames[$groupKey] = [];
            }
            $groupFrames[$groupKey][] = $frame;
        }

        // Format output in tabular format: [Project][Activity (alias)][Duration][Issue ID][Description]
        $rows = [];
        foreach ($report['issueKeys'] as $groupData) {
            $groupIssueKeys = $groupData['issueKeys'];
            $activity = $groupData['activity'];
            $activityKey = $activity['entityKey']['id'];
            $activityName = $activity['name'];

            // Create group key to find matching frames
            $sortedIssueKeys = $groupIssueKeys;
            sort($sortedIssueKeys);
            $groupKey = json_encode($sortedIssueKeys) . '|' . $activityKey;

            // Get frames for this group
            $groupFramesList = $groupFrames[$groupKey] ?? [];

            // Group frames by project for display
            $projectGroups = [];
            foreach ($groupFramesList as $frame) {
                $projectEntityKey = $frame->activity->projectEntityKey;
                $projectKey = $projectEntityKey->toString();
                $projectName = $this->getProjectName($frame);

                if (!isset($projectGroups[$projectKey])) {
                    $projectGroups[$projectKey] = [
                        'name' => $projectName,
                        'frames' => [],
                    ];
                }
                $projectGroups[$projectKey]['frames'][] = $frame;
            }

            // Create rows for each project
            foreach ($projectGroups as $project) {
                $projectName = $this->abbreviateProjectName($project['name']);
                $activityAlias = $groupFramesList[0]->activity->alias ?? '';
                $activityDisplay = $activityAlias !== ''
                    ? $activityAlias
                    : $activityName;
                $duration = $this->formatDurationWithoutSeconds($groupData['time']);

                // Collect and deduplicate descriptions
                $descriptions = [];
                foreach ($project['frames'] as $frame) {
                    if ($frame->description !== '') {
                        $descriptions[] = $frame->description;
                    }
                }
                $descriptions = $this->deduplicateDescriptions($descriptions);
                $descriptionText = implode(' ', $descriptions);

                // Format issue keys: stack vertically (one per line)
                $displayIssueKeys = [];
                foreach ($groupIssueKeys as $issueKey) {
                    $displayIssueKeys[] = $issueKey === '(no issue key)' ? '-' : $issueKey;
                }
                $displayIssueKey = implode("\n", $displayIssueKeys);

                $rows[] = [
                    'project' => $projectName,
                    'activity' => $activityDisplay,
                    'duration' => $duration,
                    'issueKey' => $displayIssueKey,
                    'description' => $descriptionText,
                ];
            }
        }

        return $this->formatSimpleTable($rows);
    }

    /**
     * Get project repository instance.
     */
    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
    }

    /**
     * Check if frame's activity is local (not from Zebra).
     *
     * @param FrameInterface $frame
     * @return bool
     */
    private function isLocalActivity(FrameInterface $frame): bool
    {
        return $frame->activity->entityKey->source === EntitySource::Local;
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
        $table->setHeaders(['Project Name', 'Activity', 'Time', 'Issue ID', 'Description']);

        $descriptionWidth = 50;

        foreach ($rows as $row) {
            // Split Issue ID by newlines (it may already be multi-line)
            $issueKeyLines = explode("\n", $row['issueKey']);
            // explode() always returns at least one element, so empty check is unnecessary
            if ($issueKeyLines === ['']) {
                $issueKeyLines = [''];
            }

            // Wrap description
            $descriptionLines = $this->wrapDescription($row['description'], $descriptionWidth);

            // Determine maximum number of lines needed
            $maxLines = max(count($issueKeyLines), count($descriptionLines));

            // Create rows aligning Issue ID and Description
            for ($lineIndex = 0; $lineIndex < $maxLines; $lineIndex++) {
                $issueKeyLine = $issueKeyLines[$lineIndex] ?? '';
                $descriptionLine = $descriptionLines[$lineIndex] ?? '';

                if ($lineIndex === 0) {
                    // First line includes all columns
                    $table->addRow([
                        $row['project'],
                        $row['activity'],
                        $row['duration'],
                        $issueKeyLine,
                        $descriptionLine,
                    ]);
                } else {
                    // Subsequent lines only show Issue ID and Description (other columns empty)
                    $table->addRow([
                        '',
                        '',
                        '',
                        $issueKeyLine,
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
}
