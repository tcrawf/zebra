<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for repository tests with common setup.
 */
abstract class RepositoryTestCase extends TestCase
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
