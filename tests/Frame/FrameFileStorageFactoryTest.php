<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Frame;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;
use Tcrawf\Zebra\Frame\FrameFileStorage;
use Tcrawf\Zebra\Frame\FrameFileStorageFactory;

class FrameFileStorageFactoryTest extends TestCase
{
    private string $originalHome;

    protected function setUp(): void
    {
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=/tmp');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
    }

    public function testCreateReturnsFrameFileStorageInstance(): void
    {
        $factory = new FrameFileStorageFactory();
        $storage = $factory->create('test.json');

        $this->assertInstanceOf(FileStorageInterface::class, $storage);
        $this->assertInstanceOf(FrameFileStorage::class, $storage);
    }

    public function testCreateWithDifferentFilenames(): void
    {
        $factory = new FrameFileStorageFactory();
        $storage1 = $factory->create('frames.json');
        $storage2 = $factory->create('current_frame.json');

        $this->assertInstanceOf(FrameFileStorage::class, $storage1);
        $this->assertInstanceOf(FrameFileStorage::class, $storage2);
    }
}
