<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Config;

use Tcrawf\Zebra\FileStorage\FileStorageInterface;

/**
 * Interface for configuration file storage operations.
 * Provides methods to get, set, and delete configuration values.
 */
interface ConfigFileStorageInterface extends FileStorageInterface
{
    /**
     * Get a specific configuration value by key.
     *
     * @param string $key The configuration key (supports dot notation, e.g., 'database.host')
     * @param mixed $default Default value if key is not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a configuration value by key.
     *
     * @param string $key The configuration key (supports dot notation, e.g., 'database.host')
     * @param mixed $value The value to set
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Delete a configuration key.
     *
     * @param string $key The configuration key (supports dot notation, e.g., 'database.host')
     * @return void
     */
    public function delete(string $key): void;
}
