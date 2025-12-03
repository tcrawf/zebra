<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Config;

use Tcrawf\Zebra\FileStorage\AbstractFileStorage;

/**
 * File storage implementation for configuration data.
 * Reads configuration from ~/.config/zebra/config.json
 */
final class ConfigFileStorage extends AbstractFileStorage implements ConfigFileStorageInterface
{
    private const string CONFIG_FILENAME = 'config.json';

    public function __construct()
    {
        parent::__construct(self::CONFIG_FILENAME);
    }

    /**
     * Get the config directory path (~/.config/zebra).
     * Cross-platform compatible.
     *
     * @return string
     */
    protected function getDirectory(): string
    {
        $homeDir = $this->getHomeDirectory();
        return $homeDir . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'zebra';
    }

    /**
     * Get a specific configuration value by key.
     *
     * @param string $key The configuration key (supports dot notation, e.g., 'database.host')
     * @param mixed $default Default value if key is not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $config = $this->read();

        if (str_contains($key, '.')) {
            // Support dot notation for nested keys
            $keys = explode('.', $key);
            $value = $config;

            foreach ($keys as $k) {
                if (!is_array($value) || !array_key_exists($k, $value)) {
                    return $default;
                }
                $value = $value[$k];
            }

            return $value;
        }

        return $config[$key] ?? $default;
    }

    /**
     * Set a configuration value by key.
     *
     * @param string $key The configuration key (supports dot notation, e.g., 'database.host')
     * @param mixed $value The value to set
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $config = $this->read();

        if (str_contains($key, '.')) {
            // Support dot notation for nested keys
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $target = &$config;

            foreach ($keys as $k) {
                if (!isset($target[$k]) || !is_array($target[$k])) {
                    $target[$k] = [];
                }
                $target = &$target[$k];
            }

            $target[$lastKey] = $value;
        } else {
            $config[$key] = $value;
        }

        $this->write($config);
    }

    /**
     * Delete a configuration key.
     *
     * @param string $key The configuration key (supports dot notation, e.g., 'database.host')
     * @return void
     */
    public function delete(string $key): void
    {
        $config = $this->read();

        if (str_contains($key, '.')) {
            // Support dot notation for nested keys
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $target = &$config;

            foreach ($keys as $k) {
                if (!isset($target[$k]) || !is_array($target[$k])) {
                    // Key path doesn't exist, nothing to delete
                    return;
                }
                $target = &$target[$k];
            }

            if (isset($target[$lastKey])) {
                unset($target[$lastKey]);
            }
        } else {
            if (isset($config[$key])) {
                unset($config[$key]);
            }
        }

        $this->write($config);
    }

    /**
     * Write configuration data to the config file.
     * Overrides parent to use JSON_UNESCAPED_SLASHES flag for cleaner JSON output.
     *
     * @param array<string, mixed> $config
     * @return void
     */
    public function write(array $config): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }
        file_put_contents($this->filePath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
