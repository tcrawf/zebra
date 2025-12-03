<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use Tcrawf\Zebra\FileStorage\AbstractFileStorage;

/**
 * File storage implementation for timesheet data.
 * Stores timesheet files in ~/.zebra directory.
 */
final class TimesheetFileStorage extends AbstractFileStorage
{
    /**
     * Get the timesheet directory path (~/.zebra).
     * Cross-platform compatible.
     *
     * @return string
     */
    protected function getDirectory(): string
    {
        $homeDir = $this->getHomeDirectory();
        return $homeDir . DIRECTORY_SEPARATOR . '.zebra';
    }
}
