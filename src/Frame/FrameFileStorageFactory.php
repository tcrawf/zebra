<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Factory for creating FrameFileStorage instances.
 */
final class FrameFileStorageFactory implements FrameFileStorageFactoryInterface
{
    /**
     * Create a frame file storage instance for the given filename.
     *
     * @param string $filename The frame filename (e.g., 'frames.json', 'current_frame.json')
     * @return FileStorageInterface
     */
    public function create(string $filename): FileStorageInterface
    {
        return new FrameFileStorage($filename);
    }
}
