<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\FileStorage;

/**
 * Trait providing cross-platform home directory detection.
 */
trait HomeDirectoryTrait
{
    /**
     * Get the user's home directory in a cross-platform way.
     *
     * @return string
     * @throws \RuntimeException If unable to determine home directory
     */
    protected function getHomeDirectory(): string
    {
        // Try HOME first (Unix/Linux/Mac)
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            return $home;
        }

        // Try USERPROFILE (Windows)
        $home = getenv('USERPROFILE');
        if ($home !== false && $home !== '') {
            return $home;
        }

        // Try HOMEDRIVE + HOMEPATH (Windows)
        $homeDrive = getenv('HOMEDRIVE');
        $homePath = getenv('HOMEPATH');
        if ($homeDrive !== false && $homePath !== false) {
            return $homeDrive . $homePath;
        }

        // Fallback for CI environments or when HOME is not set
        // Use system temp directory as a last resort
        $tempDir = sys_get_temp_dir();
        if ($tempDir !== '') {
            return $tempDir;
        }

        // Final fallback (shouldn't normally happen)
        throw new \RuntimeException('Unable to determine home directory');
    }
}
