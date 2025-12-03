<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Helper;

/**
 * Trait for managing HOME environment variable in tests.
 */
trait HomeEnvironmentTrait
{
    private string $originalHome = '';

    /**
     * Set up HOME environment variable.
     *
     * @param string $homeDir The directory to set as HOME
     * @return void
     */
    protected function setupHomeEnvironment(string $homeDir): void
    {
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $homeDir);
    }

    /**
     * Restore HOME environment variable to original value.
     *
     * @return void
     */
    protected function restoreHomeEnvironment(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
    }
}
