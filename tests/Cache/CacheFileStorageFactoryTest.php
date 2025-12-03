<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Cache\CacheFileStorage;
use Tcrawf\Zebra\Cache\CacheFileStorageFactory;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;

class CacheFileStorageFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $factory = new CacheFileStorageFactory();
        $storage = $factory->create('test_projects.json');

        $this->assertInstanceOf(FileStorageInterface::class, $storage);
        $this->assertInstanceOf(CacheFileStorage::class, $storage);
    }

    public function testCreateWithDifferentFilename(): void
    {
        $factory = new CacheFileStorageFactory();
        $storage1 = $factory->create('test_projects.json');
        $storage2 = $factory->create('test_frames.json');

        $this->assertNotSame($storage1, $storage2);
    }
}
