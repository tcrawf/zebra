<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Cache;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Factory for creating CacheFileStorage instances.
 */
final class CacheFileStorageFactory implements CacheFileStorageFactoryInterface
{
    /**
     * Create a cache file storage instance for the given filename.
     *
     * @param string $filename The cache filename (e.g., 'projects.json')
     * @return FileStorageInterface
     */
    public function create(string $filename): FileStorageInterface
    {
        return new CacheFileStorage($filename);
    }
}
