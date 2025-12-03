<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Interface for frame file storage factory.
 * Creates FrameFileStorage instances for given filenames.
 */
interface FrameFileStorageFactoryInterface
{
    /**
     * Create a frame file storage instance for the given filename.
     *
     * @param string $filename The frame filename (e.g., 'frames.json', 'current_frame.json')
     * @return FileStorageInterface
     */
    public function create(string $filename): FileStorageInterface;
}
