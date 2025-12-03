<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Cache;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Interface for cache file storage factory.
 * Creates CacheFileStorage instances for given filenames.
 */
interface CacheFileStorageFactoryInterface
{
    /**
     * Create a cache file storage instance for the given filename.
     *
     * @param string $filename The cache filename (e.g., 'projects.json')
     * @return FileStorageInterface
     */
    public function create(string $filename): FileStorageInterface;
}
