<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Carbon\CarbonInterface;
use Tcrawf\Zebra\Uuid\Uuid;

/**
 * Service for syncing timesheets bi-directionally between local storage and Zebra API.
 * Uses lu_date (last updated date) to determine which record is more recent.
 */
class TimesheetSyncService implements TimesheetSyncServiceInterface
{
    /**
     * @param LocalTimesheetRepositoryInterface $localRepository
     * @param ZebraTimesheetRepositoryInterface $zebraRepository
     */
    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $localRepository,
        private readonly ZebraTimesheetRepositoryInterface $zebraRepository
    ) {
    }

    /**
     * Push a local timesheet to Zebra.
     *
     * @param TimesheetInterface $timesheet The local timesheet to push
     * @param callable|null $confirmationCallback Optional callback to confirm updates.
     *                                            Should return true to proceed, false to cancel.
     *                                            Receives the timesheet as parameter.
     *                                            Only called for updates (when zebraId exists).
     * @return TimesheetInterface|null The updated timesheet with zebraId and remote data,
     *                                 or null if cancelled
     */
    public function pushLocalToZebra(
        TimesheetInterface $timesheet,
        ?callable $confirmationCallback = null
    ): ?TimesheetInterface {
        if ($timesheet->zebraId === null) {
            // Create new timesheet in Zebra (no confirmation needed for creates)
            $updatedTimesheet = $this->zebraRepository->create($timesheet);
        } else {
            // Update existing timesheet in Zebra (requires confirmation)
            if ($confirmationCallback === null) {
                // No confirmation callback provided - cancel update
                return null;
            }
            $updatedTimesheet = $this->zebraRepository->update($timesheet, $confirmationCallback);
            if ($updatedTimesheet === null) {
                return null; // User cancelled
            }
        }

        // Update local record with remote data, preserving UUID, frameUuids, and doNotSync
        $localUpdatedTimesheet = TimesheetFactory::create(
            $updatedTimesheet->activity,
            $updatedTimesheet->description,
            $updatedTimesheet->clientDescription,
            $updatedTimesheet->time,
            $updatedTimesheet->date,
            $updatedTimesheet->role,
            $updatedTimesheet->individualAction,
            $timesheet->frameUuids, // Preserve frame UUIDs
            $updatedTimesheet->zebraId,
            $updatedTimesheet->updatedAt,
            Uuid::fromHex($timesheet->uuid), // Preserve UUID
            $timesheet->doNotSync // Preserve doNotSync flag
        );

        // Save updated timesheet to local storage
        if ($timesheet->zebraId === null) {
            $this->localRepository->save($localUpdatedTimesheet);
        } else {
            $this->localRepository->update($localUpdatedTimesheet);
        }

        return $localUpdatedTimesheet;
    }

    /**
     * Pull timesheets from Zebra for a date range.
     *
     * @param CarbonInterface $from The start date (inclusive)
     * @param CarbonInterface|null $to The end date (inclusive, optional)
     * @return array<TimesheetInterface> Array of timesheets pulled from Zebra
     */
    public function pullFromZebra(CarbonInterface $from, CarbonInterface|null $to = null): array
    {
        $toDate = $to ?? $from;
        // Keep dates in Europe/Zurich timezone (API timezone) - don't convert to UTC
        $fromDate = $from->setTimezone('Europe/Zurich')->startOfDay();
        $toDateZurich = $toDate->setTimezone('Europe/Zurich')->startOfDay();

        $remoteTimesheets = $this->zebraRepository->getByDateRange($fromDate, $toDateZurich);
        $pulledTimesheets = [];

        foreach ($remoteTimesheets as $remoteTimesheet) {
            // Check if local version exists
            $localTimesheet = $remoteTimesheet->zebraId !== null
                ? $this->localRepository->getByZebraId($remoteTimesheet->zebraId)
                : null;

            if ($localTimesheet === null) {
                // New timesheet - save to local
                $this->localRepository->save($remoteTimesheet);
                $pulledTimesheets[] = $remoteTimesheet;
            } else {
                // Update existing local timesheet if remote is newer
                $localUpdatedAt = $localTimesheet->updatedAt->timestamp;
                $remoteUpdatedAt = $remoteTimesheet->updatedAt->timestamp;

                if ($remoteUpdatedAt > $localUpdatedAt) {
                    // Preserve UUID, frameUuids, and doNotSync
                    $updatedTimesheet = TimesheetFactory::create(
                        $remoteTimesheet->activity,
                        $remoteTimesheet->description,
                        $remoteTimesheet->clientDescription,
                        $remoteTimesheet->time,
                        $remoteTimesheet->date,
                        $remoteTimesheet->role,
                        $remoteTimesheet->individualAction,
                        $localTimesheet->frameUuids,
                        $remoteTimesheet->zebraId,
                        $remoteTimesheet->updatedAt,
                        Uuid::fromHex($localTimesheet->uuid),
                        $localTimesheet->doNotSync // Preserve doNotSync flag
                    );
                    $this->localRepository->update($updatedTimesheet);
                    $pulledTimesheets[] = $updatedTimesheet;
                }
            }
        }

        return $pulledTimesheets;
    }
}
