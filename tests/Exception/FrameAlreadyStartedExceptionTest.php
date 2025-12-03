<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Exception\FrameAlreadyStartedException;
use Tcrawf\Zebra\Exception\TrackException;

class FrameAlreadyStartedExceptionTest extends TestCase
{
    public function testFrameAlreadyStartedExceptionExtendsTrackException(): void
    {
        $exception = new FrameAlreadyStartedException();
        $this->assertInstanceOf(TrackException::class, $exception);
    }

    public function testFrameAlreadyStartedExceptionWithMessage(): void
    {
        $message = 'A frame is already started';
        $exception = new FrameAlreadyStartedException($message);
        $this->assertEquals($message, $exception->getMessage());
    }
}
