<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Client;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Client\ZebraApiException;

class ZebraApiExceptionTest extends TestCase
{
    public function testZebraApiExceptionExtendsRuntimeException(): void
    {
        $exception = new ZebraApiException();
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testZebraApiExceptionWithMessage(): void
    {
        $message = 'API request failed';
        $exception = new ZebraApiException($message);
        $this->assertEquals($message, $exception->getMessage());
    }

    public function testZebraApiExceptionWithCode(): void
    {
        $code = 500;
        $exception = new ZebraApiException('Test message', $code);
        $this->assertEquals($code, $exception->getCode());
    }

    public function testZebraApiExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new ZebraApiException('Test message', 0, $previous);
        $this->assertSame($previous, $exception->getPrevious());
    }
}
