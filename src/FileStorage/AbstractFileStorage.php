<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\FileStorage;

use JsonException;

/**
 * Abstract base class for file storage implementations.
 * Handles common file read/write operations.
 */
abstract class AbstractFileStorage implements FileStorageInterface
{
    use HomeDirectoryTrait;

    protected string $filePath;

    /**
     * @param string $filename The filename (e.g., 'frames.json', 'projects.json')
     */
    public function __construct(string $filename)
    {
        $directory = $this->getDirectory();
        $this->filePath = $directory . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Read data from the file.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $jsonContent = file_get_contents($this->filePath);
        if ($jsonContent === false) {
            return [];
        }

        try {
            $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Write data to the file.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function write(array $data): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }
        file_put_contents($this->filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Check if the file exists.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return file_exists($this->filePath);
    }

    /**
     * Get the directory path where files should be stored.
     * Must be implemented by subclasses.
     *
     * @return string
     */
    abstract protected function getDirectory(): string;
}
