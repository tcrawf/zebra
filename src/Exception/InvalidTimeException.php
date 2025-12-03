<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Exception;

/**
 * Exception thrown when time validation fails (e.g., future times, invalid ranges).
 */
class InvalidTimeException extends TrackException
{
}
