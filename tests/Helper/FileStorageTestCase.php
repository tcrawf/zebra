<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for file storage tests with vfsStream and HOME setup.
 */
abstract class FileStorageTestCase extends TestCase
{
    use VfsStreamTrait;
    use HomeEnvironmentTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupVfsStream();
        $this->setupHomeEnvironment($this->getTestHomeDir());
    }

    protected function tearDown(): void
    {
        $this->restoreHomeEnvironment();
        parent::tearDown();
    }
}
