<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Exception\TrackException;

class NoFrameStartedExceptionTest extends TestCase
{
    public function testNoFrameStartedExceptionExtendsTrackException(): void
    {
        $exception = new NoFrameStartedException();
        $this->assertInstanceOf(TrackException::class, $exception);
    }

    public function testNoFrameStartedExceptionWithMessage(): void
    {
        $message = 'No frame is currently started';
        $exception = new NoFrameStartedException($message);
        $this->assertEquals($message, $exception->getMessage());
    }
}
