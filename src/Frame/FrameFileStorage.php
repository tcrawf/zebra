<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Tcrawf\Zebra\FileStorage\AbstractFileStorage;

/**
 * File storage implementation for permanent frame data.
 * Stores frame files in ~/.zebra directory.
 */
final class FrameFileStorage extends AbstractFileStorage
{
    /**
     * Get the frame directory path (~/.zebra).
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
