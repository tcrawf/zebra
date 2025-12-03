<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Uuid;

use Stringable;

/**
 * Interface for UUID data transfer objects.
 */
interface UuidInterface extends Stringable
{
    /**
     * Get the UUID as a string.
     */
    public function toString(): string;


    /**
     * Get the UUID as a hexadecimal string (without dashes).
     */
    public function getHex(): string;
}
