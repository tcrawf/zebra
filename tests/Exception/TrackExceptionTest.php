<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Exception\TrackException;

class TrackExceptionTest extends TestCase
{
    public function testTrackExceptionExtendsException(): void
    {
        $exception = new TrackException();
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testTrackExceptionWithMessage(): void
    {
        $message = 'Test error message';
        $exception = new TrackException($message);
        $this->assertEquals($message, $exception->getMessage());
    }

    public function testTrackExceptionWithCode(): void
    {
        $code = 500;
        $exception = new TrackException('Test message', $code);
        $this->assertEquals($code, $exception->getCode());
    }

    public function testTrackExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new TrackException('Test message', 0, $previous);
        $this->assertSame($previous, $exception->getPrevious());
    }
}
