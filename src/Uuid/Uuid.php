<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Uuid;

use InvalidArgumentException;
use Random\RandomException;

/**
 * UUID Data Transfer Object.
 * Validates UUIDs and can generate random UUIDs if none is provided.
 * UUIDs are generated as 8-character hex strings for compact storage.
 */
readonly class Uuid implements UuidInterface
{
    private const int ID_LENGTH = 8;

    private string $id;

    /**
     * @param string|null $uuid Optional UUID string. If null, a random 8-character ID will be generated.
     *                          Must be exactly 8 hexadecimal characters.
     * @throws InvalidArgumentException If the provided UUID is invalid.
     */
    public function __construct(?string $uuid = null)
    {
        if ($uuid === null) {
            // Generate a new 8-character hex ID
            $this->id = $this->generateId();
        } else {
            // Validate the UUID
            $length = strlen($uuid);

            if ($length !== self::ID_LENGTH) {
                throw new InvalidArgumentException(
                    "Invalid UUID format: expected exactly 8 hexadecimal characters, got {$length} characters: {$uuid}"
                );
            }

            // Validate it's hexadecimal
            if (!ctype_xdigit($uuid)) {
                throw new InvalidArgumentException(
                    "Invalid UUID format: must be 8 hexadecimal characters, got: {$uuid}"
                );
            }

            // Reject string representations of integers (must contain at least one letter a-f or A-F)
            if (ctype_digit($uuid)) {
                throw new InvalidArgumentException(
                    "Invalid UUID format: UUID cannot be a string representation of an integer, got: {$uuid}"
                );
            }

            $this->id = $uuid;
        }
    }

    /**
     * Generate a random 8-character hexadecimal ID.
     * Retries if the generated ID is purely numeric (must contain at least one letter a-f or A-F).
     *
     * @throws RandomException
     */
    private function generateId(): string
    {
        $maxAttempts = 100; // Safety limit to prevent infinite loops
        $attempts = 0;

        do {
            $id = bin2hex(random_bytes(4)); // 4 bytes = 8 hex characters
            $attempts++;

            // If we've tried too many times, throw an exception
            if ($attempts > $maxAttempts) {
                throw new RandomException(
                    'Failed to generate a valid UUID after ' . $maxAttempts . ' attempts. ' .
                    'This is extremely unlikely and may indicate a system issue.'
                );
            }
        } while (ctype_digit($id)); // Retry if purely numeric

        return $id;
    }

    /**
     * Get the UUID as a string.
     */
    public function toString(): string
    {
        return $this->id;
    }

    /**
     * Get the UUID as a string (magic method for string casting).
     */
    public function __toString(): string
    {
        return $this->id;
    }

    /**
     * Get the UUID as a hexadecimal string (without dashes).
     */
    public function getHex(): string
    {
        return $this->id;
    }

    /**
     * Create a new random UUID (8-character hex ID).
     */
    public static function random(): self
    {
        return new self();
    }

    /**
     * Create a UUID from a string (explicit validation).
     */
    public static function fromString(string $uuid): self
    {
        return new self($uuid);
    }

    /**
     * Create a UUID from a hexadecimal string (without dashes).
     * Must be exactly 8 hexadecimal characters.
     */
    public static function fromHex(string $hex): self
    {
        return new self($hex);
    }
}
