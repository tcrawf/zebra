<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use Tcrawf\Zebra\FileStorage\AbstractFileStorage;

/**
 * File storage implementation for local project data.
 * Stores local project files in ~/.zebra directory.
 */
final class LocalProjectFileStorage extends AbstractFileStorage
{
    /**
     * Get the local project directory path (~/.zebra).
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
