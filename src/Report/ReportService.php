<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Report;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class ReportService implements ReportServiceInterface
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly TimezoneFormatter $timezoneFormatter
    ) {
    }

    /**
     * Generate a report of time spent grouped by project and activity.
     *
     * @param array<FrameInterface> $frames
     * @param CarbonInterface $from Start of date range
     * @param CarbonInterface $to End of date range
     * @return array<string, mixed> Report data structure
     */
    public function generateReport(array $frames, CarbonInterface $from, CarbonInterface $to): array
    {
        $projects = [];
        $totalTime = 0;

        foreach ($frames as $frame) {
            $duration = $frame->getDuration();
            if ($duration === null) {
                continue; // Skip active frames
            }

            $projectEntityKey = $frame->activity->projectEntityKey;
            $activityEntityKey = $frame->activity->entityKey;
            $activityName = $frame->activity->name;

            // Use entityKey strings as keys
            $projectKey = $projectEntityKey->toString();
            $activityKey = $activityEntityKey->toString();

            // Get project name
            $project = $this->projectRepository->get($projectEntityKey);
            $projectName = $project !== null ? $project->name : "Project {$projectKey}";

            // Initialize project if not exists
            if (!isset($projects[$projectKey])) {
                $projects[$projectKey] = [
                    'entityKey' => [
                        'source' => $projectEntityKey->source->value,
                        'id' => $projectKey,
                    ],
                    'name' => $projectName,
                    'time' => 0,
                    'activities' => [],
                ];
            }

            // Initialize activity if not exists
            if (!isset($projects[$projectKey]['activities'][$activityKey])) {
                $projects[$projectKey]['activities'][$activityKey] = [
                    'entityKey' => [
                        'source' => $activityEntityKey->source->value,
                        'id' => $activityKey,
                    ],
                    'name' => $activityName,
                    'time' => 0,
                    'issueKeys' => [],
                ];
            }

            // Group by issue-key
            $frameIssueKeys = $frame->issueKeys;
            if (empty($frameIssueKeys)) {
                $frameIssueKeys = ['(no issue key)'];
            }

            // Split time pro-rata among issue keys
            $issueKeyCount = count($frameIssueKeys);
            $timePerIssueKey = intval($duration / $issueKeyCount);
            $remainder = $duration % $issueKeyCount;

            // Add time to project and activity totals once
            $projects[$projectKey]['time'] += $duration;
            $projects[$projectKey]['activities'][$activityKey]['time'] += $duration;
            $totalTime += $duration;

            // Create an entry for each issue key in the frame (time split pro-rata)
            $index = 0;
            foreach ($frameIssueKeys as $issueKey) {
                if (!isset($projects[$projectKey]['activities'][$activityKey]['issueKeys'][$issueKey])) {
                    $projects[$projectKey]['activities'][$activityKey]['issueKeys'][$issueKey] = [
                        'issueKey' => $issueKey,
                        'time' => 0,
                    ];
                }

                // Add time to issue key (split pro-rata, remainder goes to first issue key)
                $issueKeyTime = $timePerIssueKey;
                if ($index === 0) {
                    $issueKeyTime += $remainder;
                }
                $projects[$projectKey]['activities'][$activityKey]['issueKeys'][$issueKey]['time'] += $issueKeyTime;
                $index++;
            }
        }

        // Convert activities to indexed array and sort
        foreach ($projects as &$project) {
            foreach ($project['activities'] as &$activity) {
                // Convert issue keys to indexed array and sort
                $issueKeysArray = $activity['issueKeys'];
                uksort($issueKeysArray, static function (string $a, string $b): int {
                    // Put "(no issue key)" at the end
                    if ($a === '(no issue key)' && $b !== '(no issue key)') {
                        return 1;
                    }
                    if ($a !== '(no issue key)' && $b === '(no issue key)') {
                        return -1;
                    }
                    return strcasecmp($a, $b);
                });
                $activity['issueKeys'] = array_values($issueKeysArray);
            }
            unset($activity);
            $project['activities'] = array_values($project['activities']);
            usort($project['activities'], static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($project);

        // Sort projects by name
        usort($projects, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

        // Convert to indexed array (ensure numeric keys after usort)
        // @phpstan-ignore-next-line arrayValues.list
        $projectsList = array_values($projects);

        return [
            'timespan' => [
                'from' => $from,
                'to' => $to,
            ],
            'projects' => $projectsList,
            'time' => $totalTime,
        ];
    }

    /**
     * Format report as plain text.
     *
     * @param array<string, mixed> $report
     * @return array<string> Lines of formatted output
     */
    public function formatPlainText(array $report): array
    {
        $lines = [];
        $fromLocal = $this->timezoneFormatter->toLocal($report['timespan']['from']);
        $toLocal = $this->timezoneFormatter->toLocal($report['timespan']['to']);

        $lines[] = sprintf(
            '%s -> %s',
            $fromLocal->format('D d M Y'),
            $toLocal->format('D d M Y')
        );
        $lines[] = '';

        foreach ($report['projects'] as $project) {
            $projectTime = $this->formatDuration($project['time']);
            $lines[] = sprintf('%s - %s', $project['name'], $projectTime);

            foreach ($project['activities'] as $activity) {
                $activityTime = $this->formatDuration($activity['time']);
                $lines[] = sprintf("\t[%s %s]", $activity['name'], $activityTime);

                foreach ($activity['issueKeys'] as $issueKeyEntry) {
                    $issueKeyTime = $this->formatDuration($issueKeyEntry['time']);
                    $lines[] = sprintf("\t\t%s - %s", $issueKeyEntry['issueKey'], $issueKeyTime);
                }
            }

            $lines[] = '';
        }

        $totalTime = $this->formatDuration($report['time']);
        $lines[] = sprintf('Total: %s', $totalTime);

        return $lines;
    }

    /**
     * Format report as JSON.
     *
     * @param array<string, mixed> $report
     * @return string JSON string
     */
    public function formatJson(array $report): string
    {
        // Convert Carbon instances to ISO 8601 strings for JSON
        $jsonReport = $report;
        $jsonReport['timespan']['from'] = $report['timespan']['from']->toIso8601String();
        $jsonReport['timespan']['to'] = $report['timespan']['to']->toIso8601String();

        return json_encode($jsonReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Format report as CSV.
     *
     * @param array<string, mixed> $report
     * @return string CSV string
     */
    public function formatCsv(array $report): string
    {
        $lines = [];
        $lines[] = 'from,to,project,activity,issue_key,time';

        $fromIso = $report['timespan']['from']->toIso8601String();
        $toIso = $report['timespan']['to']->toIso8601String();

        foreach ($report['projects'] as $project) {
            // Project-level row
            $lines[] = sprintf(
                '%s,%s,%s,,,%d',
                $fromIso,
                $toIso,
                $this->escapeCsv($project['name']),
                $project['time']
            );

            // Activity-level rows
            foreach ($project['activities'] as $activity) {
                // Activity-level row (without issue key)
                $lines[] = sprintf(
                    '%s,%s,%s,%s,,%d',
                    $fromIso,
                    $toIso,
                    $this->escapeCsv($project['name']),
                    $this->escapeCsv($activity['name']),
                    $activity['time']
                );

                // Issue key-level rows
                foreach ($activity['issueKeys'] as $issueKeyEntry) {
                    $lines[] = sprintf(
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

        return implode("\n", $lines);
    }

    /**
     * Format duration in seconds to human-readable format.
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        } else {
            return sprintf('%ds', $secs);
        }
    }

    /**
     * Escape CSV field.
     *
     * @param string $field
     * @return string
     */
    private function escapeCsv(string $field): string
    {
        if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"' . str_replace('"', '""', $field) . '"';
        }

        return $field;
    }

    /**
     * Generate a report of time spent grouped by issue-key set (order-independent) and activity.
     * Frames with the same set of issue keys (regardless of order) and same activity are grouped together.
     *
     * @param array<FrameInterface> $frames
     * @param CarbonInterface $from Start of date range
     * @param CarbonInterface $to End of date range
     * @return array<string, mixed> Report data structure
     */
    public function generateReportByIssueKey(array $frames, CarbonInterface $from, CarbonInterface $to): array
    {
        $groups = [];
        $totalTime = 0;

        foreach ($frames as $frame) {
            $duration = $frame->getDuration();
            if ($duration === null) {
                continue; // Skip active frames
            }

            $activityEntityKey = $frame->activity->entityKey;
            $activityName = $frame->activity->name;
            $activityKey = $activityEntityKey->toString();

            // Handle frames with no issue keys - use a special key
            $frameIssueKeys = $frame->issueKeys;
            if (empty($frameIssueKeys)) {
                $frameIssueKeys = ['(no issue key)'];
            }

            // Sort issue keys to make grouping order-independent
            $sortedIssueKeys = $frameIssueKeys;
            sort($sortedIssueKeys);

            // Create grouping key: sorted issue keys JSON + activity key
            $groupKey = json_encode($sortedIssueKeys) . '|' . $activityKey;

            // Initialize group if not exists
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'issueKeys' => $sortedIssueKeys,
                    'time' => 0,
                    'activity' => [
                        'entityKey' => [
                            'source' => $activityEntityKey->source->value,
                            'id' => $activityKey,
                        ],
                        'name' => $activityName,
                        'time' => 0,
                    ],
                ];
            }

            // Add time to group (sum durations, no pro-rata splitting)
            $groups[$groupKey]['time'] += $duration;
            $groups[$groupKey]['activity']['time'] += $duration;

            // Add total time once per frame
            $totalTime += $duration;
        }

        // Convert groups to indexed array and sort
        $issueKeysList = [];
        foreach ($groups as $group) {
            $issueKeysList[] = [
                'issueKeys' => $group['issueKeys'],
                'time' => $group['time'],
                'activity' => $group['activity'],
            ];
        }

        // Sort groups: first by activity name, then by first issue key
        usort($issueKeysList, static function ($a, $b) {
            // Compare by activity name first
            $activityCompare = strcasecmp($a['activity']['name'], $b['activity']['name']);
            if ($activityCompare !== 0) {
                return $activityCompare;
            }

            // If same activity, compare by first issue key
            $aFirstKey = $a['issueKeys'][0] ?? '';
            $bFirstKey = $b['issueKeys'][0] ?? '';

            // Put "(no issue key)" at the end
            if ($aFirstKey === '(no issue key)' && $bFirstKey !== '(no issue key)') {
                return 1;
            }
            if ($aFirstKey !== '(no issue key)' && $bFirstKey === '(no issue key)') {
                return -1;
            }

            return strcasecmp($aFirstKey, $bFirstKey);
        });

        return [
            'timespan' => [
                'from' => $from,
                'to' => $to,
            ],
            'issueKeys' => $issueKeysList,
            'time' => $totalTime,
        ];
    }

    /**
     * Format report grouped by issue-key as plain text.
     *
     * @param array<string, mixed> $report
     * @return array<string> Lines of formatted output
     */
    public function formatPlainTextByIssueKey(array $report): array
    {
        $lines = [];
        $fromLocal = $this->timezoneFormatter->toLocal($report['timespan']['from']);
        $toLocal = $this->timezoneFormatter->toLocal($report['timespan']['to']);

        $lines[] = sprintf(
            '%s -> %s',
            $fromLocal->format('D d M Y'),
            $toLocal->format('D d M Y')
        );
        $lines[] = '';

        foreach ($report['issueKeys'] as $groupData) {
            // Join multiple issue keys with comma and space
            $issueKeysStr = implode(', ', $groupData['issueKeys']);
            $groupTime = $this->formatDuration($groupData['time']);
            $lines[] = sprintf('%s - %s', $issueKeysStr, $groupTime);

            $activity = $groupData['activity'];
            $activityTime = $this->formatDuration($activity['time']);
            $lines[] = sprintf("\t[%s %s]", $activity['name'], $activityTime);

            $lines[] = '';
        }

        $totalTime = $this->formatDuration($report['time']);
        $lines[] = sprintf('Total: %s', $totalTime);

        return $lines;
    }

    /**
     * Format report grouped by issue-key as CSV.
     *
     * @param array<string, mixed> $report
     * @return string CSV string
     */
    public function formatCsvByIssueKey(array $report): string
    {
        $lines = [];
        $lines[] = 'from,to,issue_key,activity,time';

        $fromIso = $report['timespan']['from']->toIso8601String();
        $toIso = $report['timespan']['to']->toIso8601String();

        foreach ($report['issueKeys'] as $groupData) {
            // Join multiple issue keys with semicolon for CSV
            $issueKeysStr = implode(';', $groupData['issueKeys']);
            $activity = $groupData['activity'];

            // Group-level row with all issue keys and activity
            $lines[] = sprintf(
                '%s,%s,%s,%s,%d',
                $fromIso,
                $toIso,
                $this->escapeCsv($issueKeysStr),
                $this->escapeCsv($activity['name']),
                $groupData['time']
            );
        }

        return implode("\n", $lines);
    }
}
