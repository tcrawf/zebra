<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Exception;

/**
 * Exception thrown when attempting to start a frame when one is already started.
 */
class FrameAlreadyStartedException extends TrackException
{
}
