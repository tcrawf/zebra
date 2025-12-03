<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Exception\TrackException;

class InvalidTimeExceptionTest extends TestCase
{
    public function testInvalidTimeExceptionExtendsTrackException(): void
    {
        $exception = new InvalidTimeException();
        $this->assertInstanceOf(TrackException::class, $exception);
    }

    public function testInvalidTimeExceptionWithMessage(): void
    {
        $message = 'Invalid time provided';
        $exception = new InvalidTimeException($message);
        $this->assertEquals($message, $exception->getMessage());
    }
}
