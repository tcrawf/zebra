<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Helper;

use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

/**
 * Trait for setting up vfsStream virtual file system in tests.
 */
trait VfsStreamTrait
{
    private vfsStreamDirectory $root;
    private string $testHomeDir;

    /**
     * Set up vfsStream virtual file system.
     *
     * @return void
     */
    protected function setupVfsStream(): void
    {
        $this->root = vfsStream::setup('test');
        $this->testHomeDir = $this->root->url();
    }

    /**
     * Get the test home directory path.
     *
     * @return string
     */
    protected function getTestHomeDir(): string
    {
        return $this->testHomeDir;
    }

    /**
     * Get the vfsStream root directory.
     *
     * @return vfsStreamDirectory
     */
    protected function getVfsRoot(): vfsStreamDirectory
    {
        return $this->root;
    }
}
