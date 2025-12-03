<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\EntityKey;

use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Interface for entity keys.
 * Defines the contract that all entity key implementations must follow.
 */
interface EntityKeyInterface
{
    public EntitySource $source {
        get;
    }

    /**
     * Get the ID. Returns UuidInterface for Local source, int for Zebra source.
     */
    public UuidInterface|int $id {
        get;
    }

    /**
     * Get the ID as a string representation.
     * For Local source, returns the UUID hex string.
     * For Zebra source, returns the integer as a string.
     *
     * @return string
     */
    public function toString(): string;
}
