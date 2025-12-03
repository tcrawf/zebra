<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Cache\CacheFileStorage;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class CacheFileStorageTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $testHomeDir;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('test');
        $this->testHomeDir = $this->root->url();

        // Set HOME environment variable for the test
        putenv('HOME=' . $this->testHomeDir);
    }

    protected function tearDown(): void
    {
        // Clean up environment variable
        putenv('HOME');
    }

    public function testReadNonExistentFile(): void
    {
        $storage = new CacheFileStorage('test_projects.json');
        $result = $storage->read();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testWriteAndRead(): void
    {
        $storage = new CacheFileStorage('test_projects.json');
        $data = ['key1' => 'value1', 'key2' => ['nested' => 'value']];

        $storage->write($data);
        $result = $storage->read();

        $this->assertEquals($data, $result);
    }

    public function testWriteCreatesDirectory(): void
    {
        $storage = new CacheFileStorage('test_projects.json');
        $data = ['test' => 'data'];

        $storage->write($data);

        $cacheDir = $this->testHomeDir . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'zebra';
        $this->assertDirectoryExists($cacheDir);
        $this->assertFileExists($cacheDir . DIRECTORY_SEPARATOR . 'test_projects.json');
    }

    public function testExists(): void
    {
        $storage = new CacheFileStorage('test_projects.json');

        $this->assertFalse($storage->exists());

        $storage->write(['test' => 'data']);

        $this->assertTrue($storage->exists());
    }

    public function testReadInvalidJson(): void
    {
        $cacheDir = $this->testHomeDir . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'zebra';
        mkdir($cacheDir, 0755, true);
        file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'test_projects.json', 'invalid json{');

        $storage = new CacheFileStorage('test_projects.json');
        $result = $storage->read();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReadNonArrayData(): void
    {
        $cacheDir = $this->testHomeDir . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'zebra';
        mkdir($cacheDir, 0755, true);
        file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'test_projects.json', '"string"');

        $storage = new CacheFileStorage('test_projects.json');
        $result = $storage->read();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $storage = new CacheFileStorage('test_projects.json');
        $storage->write(['old' => 'data']);
        $storage->write(['new' => 'data']);

        $result = $storage->read();
        $this->assertEquals(['new' => 'data'], $result);
    }
}
