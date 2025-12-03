<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Exception;

/**
 * Exception thrown when attempting to stop or cancel a frame when none is started.
 */
class NoFrameStartedException extends TrackException
{
}
