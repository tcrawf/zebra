<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Tcrawf\Zebra\FileStorage\AbstractFileStorage;

/**
 * File storage implementation for task data.
 * Stores task files in ~/.zebra directory.
 */
final class TaskFileStorage extends AbstractFileStorage
{
    /**
     * Get the task directory path (~/.zebra).
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
