<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Cache;

use Tcrawf\Zebra\FileStorage\AbstractFileStorage;

/**
 * File storage implementation for cache data.
 * Stores cache files in ~/.cache/zebra directory.
 */
final class CacheFileStorage extends AbstractFileStorage
{
    /**
     * Get the cache directory path (~/.cache/zebra).
     * Cross-platform compatible.
     *
     * @return string
     */
    protected function getDirectory(): string
    {
        $homeDir = $this->getHomeDirectory();
        return $homeDir . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'zebra';
    }
}
