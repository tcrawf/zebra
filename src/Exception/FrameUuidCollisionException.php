<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Exception;

/**
 * Thrown when persisting a frame would overwrite a different frame with the same UUID.
 */
class FrameUuidCollisionException extends TrackException
{
}
