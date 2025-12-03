<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Config;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Config\ConfigFileStorage;
use bovigo\vfs\vfsStream;
use bovigo\vfs\vfsStreamDirectory;

class ConfigFileStorageTest extends TestCase
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
        $storage = new ConfigFileStorage();
        $result = $storage->read();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testWriteAndRead(): void
    {
        $storage = new ConfigFileStorage();
        $data = ['key1' => 'value1', 'key2' => ['nested' => 'value']];

        $storage->write($data);
        $result = $storage->read();

        $this->assertEquals($data, $result);
    }

    public function testWriteCreatesDirectory(): void
    {
        $storage = new ConfigFileStorage();
        $data = ['test' => 'data'];

        $storage->write($data);

        $configDir = $this->testHomeDir . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'zebra';
        $this->assertDirectoryExists($configDir);
        $this->assertFileExists($configDir . DIRECTORY_SEPARATOR . 'config.json');
    }

    public function testExists(): void
    {
        $storage = new ConfigFileStorage();

        $this->assertFalse($storage->exists());

        $storage->write(['test' => 'data']);

        $this->assertTrue($storage->exists());
    }

    public function testGet(): void
    {
        $storage = new ConfigFileStorage();
        $storage->write(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertEquals('value1', $storage->get('key1'));
        $this->assertEquals('value2', $storage->get('key2'));
        $this->assertNull($storage->get('non-existent'));
        $this->assertEquals('default', $storage->get('non-existent', 'default'));
    }

    public function testGetWithDotNotation(): void
    {
        $storage = new ConfigFileStorage();
        $storage->write([
            'database' => [
                'host' => 'localhost',
                'port' => 3306
            ]
        ]);

        $this->assertEquals('localhost', $storage->get('database.host'));
        $this->assertEquals(3306, $storage->get('database.port'));
        $this->assertNull($storage->get('database.non-existent'));
    }

    public function testSet(): void
    {
        $storage = new ConfigFileStorage();
        $storage->set('key1', 'value1');

        $this->assertEquals('value1', $storage->get('key1'));
    }

    public function testSetWithDotNotation(): void
    {
        $storage = new ConfigFileStorage();
        $storage->set('database.host', 'localhost');
        $storage->set('database.port', 3306);

        $this->assertEquals('localhost', $storage->get('database.host'));
        $this->assertEquals(3306, $storage->get('database.port'));

        $all = $storage->read();
        $this->assertArrayHasKey('database', $all);
        $this->assertArrayHasKey('host', $all['database']);
        $this->assertArrayHasKey('port', $all['database']);
    }

    public function testDelete(): void
    {
        $storage = new ConfigFileStorage();
        $storage->write(['key1' => 'value1', 'key2' => 'value2']);
        $storage->delete('key1');

        $this->assertNull($storage->get('key1'));
        $this->assertEquals('value2', $storage->get('key2'));
    }

    public function testDeleteWithDotNotation(): void
    {
        $storage = new ConfigFileStorage();
        $storage->write([
            'database' => [
                'host' => 'localhost',
                'port' => 3306
            ]
        ]);

        $storage->delete('database.host');

        $this->assertNull($storage->get('database.host'));
        $this->assertEquals(3306, $storage->get('database.port'));
    }

    public function testDeleteNonExistentKey(): void
    {
        $storage = new ConfigFileStorage();
        $storage->write(['key1' => 'value1']);

        // Should not throw an exception
        $storage->delete('non-existent');
        $this->assertEquals('value1', $storage->get('key1'));
    }

    public function testReadInvalidJson(): void
    {
        $configDir = $this->testHomeDir . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'zebra';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'config.json', 'invalid json{');

        $storage = new ConfigFileStorage();
        $result = $storage->read();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
