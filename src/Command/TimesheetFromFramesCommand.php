<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\CarbonInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Trait\FormattingTrait;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Report\ReportServiceInterface;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetDateHelper;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\TimesheetInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetFromFramesCommand extends Command
{
    use FormattingTrait;

    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly ReportServiceInterface $reportService,
        private readonly LocalTimesheetRepositoryInterface $timesheetRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('timesheet:from-frames')
            ->setDescription('Create timesheet entries from aggregated frames for a given day')
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
                'Create timesheets for yesterday'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be created without actually creating timesheets'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse date using centralized helper (defaults to today)
        try {
            $date = TimesheetDateHelper::parseDateInput($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run') === true;

        try {
            // Get frames for the day
            // Convert Europe/Zurich date to UTC for frame queries (frames use UTC)
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

            if (empty($frames)) {
                $io->info("No frames found for {$date->format('Y-m-d')}");
                return Command::SUCCESS;
            }

            // Filter out active frames (they don't have a duration)
            $completedFrames = array_filter($frames, static fn($frame) => !$frame->isActive());

            if (empty($completedFrames)) {
                $dateStr = $date->format('Y-m-d');
                $io->info("No completed frames found for {$dateStr}");
                return Command::SUCCESS;
            }

            // Filter to only Zebra activities (timesheets can only be created for Zebra activities)
            $zebraFrames = array_filter(
                $completedFrames,
                static fn($frame) => $frame->activity->entityKey->source === EntitySource::Zebra
            );

            if (empty($zebraFrames)) {
                $dateStr = $date->format('Y-m-d');
                $io->info("No Zebra activity frames found for {$dateStr}");
                return Command::SUCCESS;
            }

            // Sort frames by start time for consistent aggregation
            usort($zebraFrames, static fn($a, $b) => $a->startTime->timestamp <=> $b->startTime->timestamp);

            // Generate report using the same logic as aggregate command
            $report = $this->reportService->generateReportByIssueKey(
                $zebraFrames,
                $dayStart,
                $dayEnd
            );

            // Store frames with report for processing
            $report['frames'] = $zebraFrames;

            // Create timesheets from report groups
            $createdTimesheets = $this->createTimesheetsFromReport($report, $date, $dryRun, $io);

            if ($dryRun) {
                $io->note('Dry run mode - no timesheets were actually created');
            } else {
                $count = count($createdTimesheets);
                $dateStr = $date->format('Y-m-d');
                $io->success(sprintf('Created %d timesheet(s) for %s', $count, $dateStr));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Create timesheets from aggregated report.
     *
     * @param array<string, mixed> $report
     * @param CarbonInterface $date
     * @param bool $dryRun
     * @param SymfonyStyle $io
     * @return array<TimesheetInterface>
     */
    private function createTimesheetsFromReport(
        array $report,
        CarbonInterface $date,
        bool $dryRun,
        SymfonyStyle $io
    ): array {
        $createdTimesheets = [];
        $frames = $report['frames'] ?? [];

        // Build mapping of groups to frames for description and role lookup
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

        // Process each group in the report
        foreach ($report['issueKeys'] as $groupData) {
            $groupIssueKeys = $groupData['issueKeys'];
            $activity = $groupData['activity'];
            $activityKey = $activity['entityKey']['id'];

            // Create group key to find matching frames
            $sortedIssueKeys = $groupIssueKeys;
            sort($sortedIssueKeys);
            $groupKey = json_encode($sortedIssueKeys) . '|' . $activityKey;

            // Get frames for this group
            $groupFramesList = $groupFrames[$groupKey] ?? [];

            if (empty($groupFramesList)) {
                continue; // Skip if no frames found (shouldn't happen, but be safe)
            }

            // Get activity object from first frame
            $firstFrame = $groupFramesList[0];
            $activityObj = $firstFrame->activity;

            // Skip if activity is not from Zebra
            if ($activityObj->entityKey->source !== EntitySource::Zebra) {
                continue;
            }

            // Convert time from seconds to hours, rounded to nearest 0.25 (15 minutes)
            $timeSeconds = $groupData['time'];
            $timeHoursRaw = $timeSeconds / 3600;

            // Rounding logic:
            // - Times <= 0.25h: round up to 0.25h (minimum billable time)
            // - Times > 0.25h: round to nearest 0.25h (standard rounding)
            // - Times > 0.25h with activity alias starting with underscore: round DOWN to nearest 0.25h
            if ($timeHoursRaw <= 0.25) {
                $timeHours = 0.25; // Minimum billable time
            } else {
                // Check if activity alias starts with underscore
                $alias = $activityObj->alias ?? '';
                if (str_starts_with($alias, '_')) {
                    // Round down to nearest 0.25h
                    $timeHours = floor($timeHoursRaw / 0.25) * 0.25;
                } else {
                    // Round to nearest 0.25h (standard rounding)
                    $timeHours = round($timeHoursRaw * 4) / 4;
                }
            }

            // Collect and deduplicate descriptions
            $descriptions = [];
            foreach ($groupFramesList as $frame) {
                if ($frame->description !== '') {
                    $descriptions[] = $frame->description;
                }
            }
            $descriptions = $this->deduplicateDescriptions($descriptions);
            $descriptionText = implode(' ', $descriptions);

            // If no description, use a default
            if (empty(trim($descriptionText))) {
                $descriptionText = 'Time entry';
            }

            // Determine role and individual action flag
            // Use the role from the first frame, or most common role if different
            $role = $this->determineRole($groupFramesList);
            $isIndividual = $this->determineIndividualAction($groupFramesList);

            // Collect frame UUIDs
            $frameUuids = array_map(static fn($frame) => $frame->uuid, $groupFramesList);

            // Check for duplicates: see if any of these frames are already in a timesheet
            // Get all existing timesheets for this date
            $existingTimesheets = $this->timesheetRepository->getByDateRange($date, $date);
            $isDuplicate = false;
            $duplicateTimesheet = null;

            foreach ($existingTimesheets as $existingTimesheet) {
                // Check if any frame UUIDs overlap
                $existingFrameUuids = $existingTimesheet->frameUuids;
                $overlappingFrames = array_intersect($frameUuids, $existingFrameUuids);

                if (!empty($overlappingFrames)) {
                    // Frame UUIDs overlap - this is a duplicate
                    $isDuplicate = true;
                    $duplicateTimesheet = $existingTimesheet;
                    break;
                }
            }

            if ($isDuplicate) {
                // Merge new frame UUIDs with existing ones (only add ones that aren't already there)
                $existingFrameUuids = $duplicateTimesheet->frameUuids;
                $newFrameUuids = array_unique(array_merge($existingFrameUuids, $frameUuids));
                $addedFrames = array_diff($newFrameUuids, $existingFrameUuids);

                if (!empty($addedFrames)) {
                    // Update existing timesheet with merged frame UUIDs, preserving time
                    $updatedTimesheet = TimesheetFactory::create(
                        $duplicateTimesheet->activity,
                        $duplicateTimesheet->description,
                        $duplicateTimesheet->clientDescription,
                        $duplicateTimesheet->time, // Preserve existing time
                        $duplicateTimesheet->date,
                        $duplicateTimesheet->role,
                        $duplicateTimesheet->individualAction,
                        $newFrameUuids, // Merged frame UUIDs
                        $duplicateTimesheet->zebraId,
                        $duplicateTimesheet->updatedAt,
                        Uuid::fromHex($duplicateTimesheet->uuid), // Preserve UUID
                        $duplicateTimesheet->doNotSync // Preserve doNotSync flag
                    );

                    if ($dryRun) {
                        $addedCount = count($addedFrames);
                        $io->writeln(sprintf(
                            '<info>Would update timesheet:</info> %s - %s - %.2f hours ' .
                            '(adding %d frame%s, UUID: %s)',
                            $duplicateTimesheet->activity->name,
                            $duplicateTimesheet->description,
                            $duplicateTimesheet->time,
                            $addedCount,
                            $addedCount !== 1 ? 's' : '',
                            substr($duplicateTimesheet->uuid, 0, 8)
                        ));
                    } else {
                        $this->timesheetRepository->update($updatedTimesheet);
                        $addedCount = count($addedFrames);
                        $io->writeln(sprintf(
                            '<info>Updated timesheet:</info> %s - %s - %.2f hours ' .
                            '(added %d frame%s, UUID: %s)',
                            $duplicateTimesheet->activity->name,
                            $duplicateTimesheet->description,
                            $duplicateTimesheet->time,
                            $addedCount,
                            $addedCount !== 1 ? 's' : '',
                            substr($duplicateTimesheet->uuid, 0, 8)
                        ));
                        $createdTimesheets[] = $updatedTimesheet;
                    }
                } else {
                    // All frames already in timesheet, skip
                    $frameCount = count($frameUuids);
                    $io->note(sprintf(
                        'Skipping duplicate timesheet: %s - %s - %.2f hours (%d frame%s) ' .
                        '(all frames already exist in UUID: %s)',
                        $activityObj->name,
                        $descriptionText,
                        $timeHours,
                        $frameCount,
                        $frameCount !== 1 ? 's' : '',
                        substr($duplicateTimesheet->uuid, 0, 8)
                    ));
                }
                continue;
            }

            // Create timesheet
            try {
                $timesheet = TimesheetFactory::create(
                    $activityObj,
                    $descriptionText,
                    null, // No client description from frames
                    $timeHours,
                    $date,
                    $role,
                    $isIndividual,
                    $frameUuids,
                    null, // No zebraId initially
                    null  // updatedAt will default to current time
                );

                if ($dryRun) {
                    $frameCount = count($frameUuids);
                    $io->writeln(sprintf(
                        '<info>Would create:</info> %s - %s - %.2f hours - %s (%d frame%s)',
                        $activityObj->name,
                        $descriptionText,
                        $timeHours,
                        $isIndividual ? 'Individual' : ($role !== null ? $role->name : 'No role'),
                        $frameCount,
                        $frameCount !== 1 ? 's' : ''
                    ));
                } else {
                    $this->timesheetRepository->save($timesheet);
                    $frameCount = count($frameUuids);
                    $io->writeln(sprintf(
                        '<info>Created:</info> %s - %s - %.2f hours (%d frame%s)',
                        $activityObj->name,
                        $descriptionText,
                        $timeHours,
                        $frameCount,
                        $frameCount !== 1 ? 's' : ''
                    ));
                }

                $createdTimesheets[] = $timesheet;
            } catch (\Exception $e) {
                $io->warning(sprintf(
                    'Failed to create timesheet for %s: %s',
                    $activityObj->name,
                    $e->getMessage()
                ));
            }
        }

        return $createdTimesheets;
    }

    /**
     * Determine role from frames.
     * Uses the role from the first frame, or most common role if frames have different roles.
     *
     * @param array<FrameInterface> $frames
     * @return RoleInterface|null
     */
    private function determineRole(array $frames): ?RoleInterface
    {
        if (empty($frames)) {
            return null;
        }

        // Check if all frames have the same role
        $firstRole = $frames[0]->role;
        $allSameRole = true;
        foreach ($frames as $frame) {
            if ($frame->role !== $firstRole) {
                $allSameRole = false;
                break;
            }
        }

        if ($allSameRole) {
            return $firstRole;
        }

        // If different roles, find most common role
        $roleCounts = [];
        foreach ($frames as $frame) {
            if ($frame->role !== null) {
                $roleId = $frame->role->id;
                $roleCounts[$roleId] = ($roleCounts[$roleId] ?? 0) + 1;
            }
        }

        if (empty($roleCounts)) {
            return null;
        }

        // Get role ID with highest count
        $mostCommonRoleId = array_keys($roleCounts, max($roleCounts), true)[0];

        // Find the role object
        foreach ($frames as $frame) {
            if ($frame->role !== null && $frame->role->id === $mostCommonRoleId) {
                return $frame->role;
            }
        }

        return null;
    }

    /**
     * Determine if timesheet should be marked as individual action.
     * Returns true if any frame is individual.
     *
     * @param array<FrameInterface> $frames
     * @return bool
     */
    private function determineIndividualAction(array $frames): bool
    {
        foreach ($frames as $frame) {
            if ($frame->isIndividual) {
                return true;
            }
        }

        return false;
    }
}
