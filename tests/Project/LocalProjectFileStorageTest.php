<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Project;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Project\LocalProjectFileStorage;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class LocalProjectFileStorageTest extends TestCase
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

    public function testLocalProjectFileStorageGetDirectory(): void
    {
        $storage = new LocalProjectFileStorage('test.json');
        $expectedDir = $this->testHomeDir . DIRECTORY_SEPARATOR . '.zebra';

        // Test by writing and checking file location
        $storage->write(['test' => 'data']);
        $filePath = $expectedDir . DIRECTORY_SEPARATOR . 'test.json';
        $this->assertFileExists($filePath);
    }

    public function testLocalProjectFileStorageReadWrite(): void
    {
        $storage = new LocalProjectFileStorage('projects.json');
        $data = ['project1' => ['name' => 'Test Project']];

        $storage->write($data);
        $result = $storage->read();

        $this->assertEquals($data, $result);
    }

    public function testLocalProjectFileStorageExists(): void
    {
        $storage = new LocalProjectFileStorage('projects.json');
        $this->assertFalse($storage->exists());

        $storage->write(['test' => 'data']);
        $this->assertTrue($storage->exists());
    }
}
