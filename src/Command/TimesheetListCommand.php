<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\CarbonInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Trait\FormattingTrait;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetDateHelper;

class TimesheetListCommand extends Command
{
    use FormattingTrait;

    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $timesheetRepository,
        private readonly FrameRepositoryInterface $frameRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('timesheet:list')
            ->setAliases(['timesheets'])
            ->setDescription('List timesheets for a given day')
            ->addOption(
                'date',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Date for the timesheets (YYYY-MM-DD format, defaults to today)',
                null
            )
            ->addOption(
                'yesterday',
                null,
                InputOption::VALUE_NONE,
                'Show timesheets for yesterday'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse date using centralized helper
        try {
            $date = TimesheetDateHelper::parseDateInput($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Get timesheets for the date
        $timesheets = $this->timesheetRepository->getByDateRange($date, $date);

        // Calculate total before checking if empty (always show total)
        $totalTime = array_sum(array_map(static fn($t) => $t->time, $timesheets));
        $totalHours = round($totalTime * 4) / 4; // Round to nearest 0.25

        // Filter syncable timesheets (exclude those flagged as doNotSync)
        $syncableTimesheets = array_filter(
            $timesheets,
            static fn($t) => !$t->doNotSync
        );
        $totalTimeSyncable = array_sum(array_map(static fn($t) => $t->time, $syncableTimesheets));
        $totalHoursSyncable = round($totalTimeSyncable * 4) / 4; // Round to nearest 0.25

        // Get frames for the date and calculate frame totals
        $zurichDate = $date->copy()->setTimezone('Europe/Zurich');
        $dayStart = $zurichDate->copy()->startOfDay()->utc();
        $dayEnd = $zurichDate->copy()->endOfDay()->utc();

        $frames = $this->frameRepository->filter(
            [],
            [],
            [],
            [],
            $dayStart,
            $dayEnd,
            true // include partial frames
        );

        // Filter to only completed frames with Zebra activities
        $zebraFrames = array_filter(
            $frames,
            static fn($frame) => !$frame->isActive()
                && $frame->activity->entityKey->source === EntitySource::Zebra
        );

        // Calculate total frame duration in seconds (not rounded)
        $totalFrameSeconds = 0;
        foreach ($zebraFrames as $frame) {
            $duration = $frame->getDuration();
            if ($duration !== null) {
                $totalFrameSeconds += $duration;
            }
        }
        $totalFrameHours = $totalFrameSeconds / 3600; // Convert to hours for delta calculation

        // Calculate delta in seconds (syncable timesheets - frame total, not rounded)
        $totalSyncableSeconds = (int) round($totalHoursSyncable * 3600);
        $deltaSeconds = $totalSyncableSeconds - $totalFrameSeconds;

        if (empty($timesheets)) {
            $dateStr = TimesheetDateHelper::formatDateForStorage($date);
            $io->info("No timesheets found for {$dateStr}");
            $io->writeln('');
            $this->displayTotals($io, $totalHours, $totalHoursSyncable, (int) $totalFrameSeconds, $deltaSeconds);
            return Command::SUCCESS;
        }

        // Sort by time (descending) and then by activity name
        usort($timesheets, static function ($a, $b) {
            $timeCompare = $b->time <=> $a->time;
            if ($timeCompare !== 0) {
                return $timeCompare;
            }
            return strcasecmp($a->activity->name, $b->activity->name);
        });

        // Format and display
        $outputContent = $this->formatTimesheets($timesheets, $date);
        $io->writeln($outputContent);

        // Display totals
        $io->writeln('');
        $this->displayTotals($io, $totalHours, $totalHoursSyncable, (int) $totalFrameSeconds, $deltaSeconds);

        return Command::SUCCESS;
    }

    /**
     * Format timesheets as a table.
     *
     * @param array<\Tcrawf\Zebra\Timesheet\TimesheetInterface> $timesheets
     * @param CarbonInterface $date
     * @return string
     */
    private function formatTimesheets(array $timesheets, CarbonInterface $date): string
    {
        if (empty($timesheets)) {
            return '';
        }

        $bufferedOutput = new BufferedOutput();
        $table = new Table($bufferedOutput);
        $table->setHeaders(['UUID', 'Zebra ID', 'Activity', 'Role', 'Frame Time', 'Time', 'Issue Keys', 'Description']);

        $descriptionWidth = 50;

        foreach ($timesheets as $timesheet) {
            $activityDisplay = $timesheet->activity->alias ?? $timesheet->activity->name;
            $timeFormatted = sprintf('%.2f', $timesheet->time);
            // Add (*) indicator for timesheets that will not sync
            if ($timesheet->doNotSync) {
                $timeFormatted .= ' (*)';
            }
            $roleDisplay = $timesheet->individualAction
                ? 'Individual'
                : ($timesheet->role !== null ? $timesheet->role->name : 'N/A');
            $description = $timesheet->description !== '' ? $timesheet->description : '';
            $uuidDisplay = $timesheet->uuid; // Show full UUID for editing
            $zebraIdDisplay = $timesheet->zebraId !== null ? (string) $timesheet->zebraId : '-';

            // Calculate aggregated frame time
            $frameTimeFormatted = $this->calculateFrameTime($timesheet);

            // Extract issue keys from description (same pattern as frames)
            $issueKeys = $this->extractIssueKeys($description);
            // Display issue keys one per line
            $issueKeyLines = empty($issueKeys) ? ['-'] : $issueKeys;

            // Wrap description
            $descriptionLines = $this->wrapDescription($description, $descriptionWidth);

            // Determine maximum number of lines needed
            $maxLines = max(count($descriptionLines), count($issueKeyLines));

            // Create rows aligning issue keys and description
            for ($lineIndex = 0; $lineIndex < $maxLines; $lineIndex++) {
                $issueKeyLine = $issueKeyLines[$lineIndex] ?? '';
                $descriptionLine = $descriptionLines[$lineIndex] ?? '';

                if ($lineIndex === 0) {
                    // First line includes all columns
                    $table->addRow([
                        $uuidDisplay,
                        $zebraIdDisplay,
                        $activityDisplay,
                        $roleDisplay,
                        $frameTimeFormatted,
                        $timeFormatted,
                        $issueKeyLine,
                        $descriptionLine,
                    ]);
                } else {
                    // Subsequent lines only show issue keys and description (other columns empty)
                    $table->addRow([
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        $issueKeyLine,
                        $descriptionLine,
                    ]);
                }
            }
        }

        $table->setColumnMaxWidth(7, $descriptionWidth);
        $table->render();

        $renderedTable = $bufferedOutput->fetch();
        $lines = explode("\n", rtrim($renderedTable, "\n"));

        // Add header with date (formatted for display in local timezone)
        $dateHeader = TimesheetDateHelper::formatDateForDisplay($date);
        array_unshift($lines, $dateHeader);
        array_unshift($lines, '');

        // Add legend before totals
        $lines[] = '';
        $lines[] = '(*) Do not sync';

        return implode("\n", $lines);
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
     * Calculate aggregated frame time for a timesheet.
     * Recalculates based on Activity and Issue Keys (regardless of order),
     * not from the referenced frames.
     *
     * @param \Tcrawf\Zebra\Timesheet\TimesheetInterface $timesheet
     * @return string Formatted frame time or '-'
     */
    private function calculateFrameTime(\Tcrawf\Zebra\Timesheet\TimesheetInterface $timesheet): string
    {
        // Extract issue keys from timesheet description
        $timesheetIssueKeys = $this->extractIssueKeys($timesheet->description);
        sort($timesheetIssueKeys);

        // Get the date range for the timesheet (same day)
        $timesheetDate = $timesheet->date->setTimezone('Europe/Zurich');
        $dayStart = $timesheetDate->copy()->startOfDay()->utc();
        $dayEnd = $timesheetDate->copy()->endOfDay()->utc();

        // Get all frames for this date
        $frames = $this->frameRepository->filter(
            [],
            [],
            [],
            [],
            $dayStart,
            $dayEnd,
            true // include partial frames
        );

        // Filter to only completed frames with matching activity and issue keys
        $matchingFrames = [];
        foreach ($frames as $frame) {
            // Must be completed (not active)
            if ($frame->isActive()) {
                continue;
            }

            // Must be a Zebra activity
            if ($frame->activity->entityKey->source !== EntitySource::Zebra) {
                continue;
            }

            // Must have same activity
            if ($frame->activity->entityKey->toString() !== $timesheet->activity->entityKey->toString()) {
                continue;
            }

            // Must have same issue keys (regardless of order)
            $frameIssueKeys = $frame->issueKeys;
            sort($frameIssueKeys);
            if ($frameIssueKeys !== $timesheetIssueKeys) {
                continue;
            }

            $matchingFrames[] = $frame;
        }

        // If no matching frames found, return '-'
        if (empty($matchingFrames)) {
            return '-';
        }

        // Aggregate duration from all matching frames
        $totalSeconds = 0;
        foreach ($matchingFrames as $frame) {
            $duration = $frame->getDuration();
            if ($duration !== null) {
                $totalSeconds += $duration;
            }
        }

        // Convert seconds to hours (decimal)
        $totalHours = $totalSeconds / 3600;

        return sprintf('%.2f', $totalHours);
    }

    /**
     * Extract issue keys from description.
     * Issue keys have the format: 2-6 uppercase letters, hyphen, 1-5 digits (e.g., AA-1234, ABC-12345).
     * Uses the same pattern as Frame::extractIssues().
     *
     * @param string $description The description to extract issue keys from
     * @return array<string> Array of issue keys found in the description
     */
    private function extractIssueKeys(string $description): array
    {
        if (empty($description)) {
            return [];
        }

        // Pattern: 2-6 uppercase letters, hyphen, 1-5 digits
        $pattern = '/[A-Z]{2,6}-\d{1,5}/';
        preg_match_all($pattern, $description, $matches);

        // Return unique issue keys
        // preg_match_all always populates $matches[0], even if empty
        return array_values(array_unique($matches[0]));
    }

    /**
     * Display totals: timesheet total, syncable timesheets, frame total, and delta.
     *
     * @param SymfonyStyle $io
     * @param float $totalHours Timesheet total hours (all timesheets)
     * @param float $totalHoursSyncable Syncable timesheets hours (excluding flagged frames)
     * @param int $totalFrameSeconds Frame total seconds (not rounded)
     * @param int $deltaSeconds Delta in seconds (syncable timesheets - frame total, not rounded)
     * @return void
     */
    private function displayTotals(
        SymfonyStyle $io,
        float $totalHours,
        float $totalHoursSyncable,
        int $totalFrameSeconds,
        int $deltaSeconds
    ): void {
        $io->writeln(sprintf('<info>Timesheet total: %.2f hours</info>', $totalHours));
        $io->writeln(sprintf('<info>Timesheets (syncable): %.2f hours</info>', $totalHoursSyncable));
        $frameTotalFormatted = $this->formatDurationWithoutSeconds($totalFrameSeconds);
        $io->writeln(sprintf('<info>Frame total (Zebra): %s</info>', $frameTotalFormatted));

        // Display delta with appropriate color/style
        if ($deltaSeconds === 0) {
            // Delta is zero
            $deltaFormatted = $this->formatDurationWithoutSeconds(abs($deltaSeconds));
            $io->writeln(sprintf('<info>Delta: %s</info>', $deltaFormatted));
        } elseif ($deltaSeconds > 0) {
            // Syncable timesheets have more time than frames (positive delta)
            $deltaFormatted = $this->formatDurationWithoutSeconds($deltaSeconds);
            $io->writeln(sprintf('<comment>Delta: +%s</comment>', $deltaFormatted));
        } else {
            // Frames have more time than syncable timesheets (negative delta)
            $deltaFormatted = $this->formatDurationWithoutSeconds(abs($deltaSeconds));
            $io->writeln(sprintf('<comment>Delta: -%s</comment>', $deltaFormatted));
        }
    }
}
