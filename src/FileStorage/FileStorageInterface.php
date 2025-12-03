<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\FileStorage;

/**
 * Common interface for file storage operations.
 * Provides methods to read and write data to files.
 */
interface FileStorageInterface
{
    /**
     * Read data from the file.
     *
     * @return array<string, mixed>
     */
    public function read(): array;

    /**
     * Write data to the file.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function write(array $data): void;

    /**
     * Check if the file exists.
     *
     * @return bool
     */
    public function exists(): bool;
}
