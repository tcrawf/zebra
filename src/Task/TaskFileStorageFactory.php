<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Factory for creating TaskFileStorage instances.
 */
final class TaskFileStorageFactory implements TaskFileStorageFactoryInterface
{
    /**
     * Create a task file storage instance for the given filename.
     *
     * @param string $filename The task filename (e.g., 'tasks.json')
     * @return FileStorageInterface
     */
    public function create(string $filename): FileStorageInterface
    {
        return new TaskFileStorage($filename);
    }
}
