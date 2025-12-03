<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Frame;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Frame\FrameFileStorage;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class FrameFileStorageTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $testHomeDir;
    private string $originalHome;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('test');
        $this->testHomeDir = $this->root->url();
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->testHomeDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
    }

    public function testFrameFileStorageGetDirectory(): void
    {
        $storage = new FrameFileStorage('test.json');
        $expectedDir = $this->testHomeDir . DIRECTORY_SEPARATOR . '.zebra';

        // Test by writing and checking file location
        $storage->write(['test' => 'data']);
        $filePath = $expectedDir . DIRECTORY_SEPARATOR . 'test.json';
        $this->assertFileExists($filePath);
    }

    public function testFrameFileStorageReadWrite(): void
    {
        $storage = new FrameFileStorage('frames.json');
        $data = ['frame1' => ['uuid' => 'abc123']];

        $storage->write($data);
        $result = $storage->read();

        $this->assertEquals($data, $result);
    }

    public function testFrameFileStorageExists(): void
    {
        $storage = new FrameFileStorage('frames.json');
        $this->assertFalse($storage->exists());

        $storage->write(['test' => 'data']);
        $this->assertTrue($storage->exists());
    }
}
