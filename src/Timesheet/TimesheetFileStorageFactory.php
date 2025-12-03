<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Factory for creating TimesheetFileStorage instances.
 */
final class TimesheetFileStorageFactory implements TimesheetFileStorageFactoryInterface
{
    /**
     * Create a file storage instance for the given filename.
     *
     * @param string $filename The timesheet filename (e.g., 'timesheets.json')
     * @return FileStorageInterface
     */
    public function create(string $filename): FileStorageInterface
    {
        return new TimesheetFileStorage($filename);
    }
}
