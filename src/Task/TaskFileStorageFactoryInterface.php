<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Interface for task file storage factory.
 */
interface TaskFileStorageFactoryInterface
{
    /**
     * Create a task file storage instance for the given filename.
     *
     * @param string $filename The task filename (e.g., 'tasks.json')
     * @return FileStorageInterface
     */
    public function create(string $filename): FileStorageInterface;
}
