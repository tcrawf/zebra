<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\FileStorage;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\FileStorage\HomeDirectoryTrait;

class HomeDirectoryTraitTest extends TestCase
{
    private string $originalHome;
    private string $originalUserProfile;
    private string $originalHomeDrive;
    private string $originalHomePath;

    protected function setUp(): void
    {
        // Store original environment variables
        $this->originalHome = getenv('HOME') ?: '';
        $this->originalUserProfile = getenv('USERPROFILE') ?: '';
        $this->originalHomeDrive = getenv('HOMEDRIVE') ?: '';
        $this->originalHomePath = getenv('HOMEPATH') ?: '';
    }

    protected function tearDown(): void
    {
        // Restore original environment variables
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalUserProfile !== '') {
            putenv('USERPROFILE=' . $this->originalUserProfile);
        } else {
            putenv('USERPROFILE');
        }

        if ($this->originalHomeDrive !== '') {
            putenv('HOMEDRIVE=' . $this->originalHomeDrive);
        } else {
            putenv('HOMEDRIVE');
        }

        if ($this->originalHomePath !== '') {
            putenv('HOMEPATH=' . $this->originalHomePath);
        } else {
            putenv('HOMEPATH');
        }
    }

    public function testGetHomeDirectoryUsesHomeEnvironmentVariable(): void
    {
        $testHome = '/test/home';
        putenv('HOME=' . $testHome);
        putenv('USERPROFILE');
        putenv('HOMEDRIVE');
        putenv('HOMEPATH');

        $trait = new class {
            use HomeDirectoryTrait;

            public function testGetHomeDirectory(): string
            {
                return $this->getHomeDirectory();
            }
        };

        $result = $trait->testGetHomeDirectory();
        $this->assertEquals($testHome, $result);
    }

    public function testGetHomeDirectoryUsesUserProfileOnWindows(): void
    {
        putenv('HOME');
        $testUserProfile = 'C:\\Users\\TestUser';
        putenv('USERPROFILE=' . $testUserProfile);
        putenv('HOMEDRIVE');
        putenv('HOMEPATH');

        $trait = new class {
            use HomeDirectoryTrait;

            public function testGetHomeDirectory(): string
            {
                return $this->getHomeDirectory();
            }
        };

        $result = $trait->testGetHomeDirectory();
        $this->assertEquals($testUserProfile, $result);
    }

    public function testGetHomeDirectoryUsesHomeDriveAndHomePathOnWindows(): void
    {
        putenv('HOME');
        putenv('USERPROFILE');
        $testHomeDrive = 'C:';
        $testHomePath = '\\Users\\TestUser';
        putenv('HOMEDRIVE=' . $testHomeDrive);
        putenv('HOMEPATH=' . $testHomePath);

        $trait = new class {
            use HomeDirectoryTrait;

            public function testGetHomeDirectory(): string
            {
                return $this->getHomeDirectory();
            }
        };

        $result = $trait->testGetHomeDirectory();
        $this->assertEquals($testHomeDrive . $testHomePath, $result);
    }

    public function testGetHomeDirectoryUsesTempDirectoryAsFallback(): void
    {
        putenv('HOME');
        putenv('USERPROFILE');
        putenv('HOMEDRIVE');
        putenv('HOMEPATH');

        $trait = new class {
            use HomeDirectoryTrait;

            public function testGetHomeDirectory(): string
            {
                return $this->getHomeDirectory();
            }
        };

        $result = $trait->testGetHomeDirectory();
        // Should fall back to system temp directory when no home directory is found
        $this->assertEquals(sys_get_temp_dir(), $result);
    }

    public function testGetHomeDirectoryPrefersHomeOverUserProfile(): void
    {
        $testHome = '/test/home';
        $testUserProfile = 'C:\\Users\\TestUser';
        putenv('HOME=' . $testHome);
        putenv('USERPROFILE=' . $testUserProfile);

        $trait = new class {
            use HomeDirectoryTrait;

            public function testGetHomeDirectory(): string
            {
                return $this->getHomeDirectory();
            }
        };

        $result = $trait->testGetHomeDirectory();
        $this->assertEquals($testHome, $result);
    }

    public function testGetHomeDirectoryPrefersUserProfileOverHomeDrivePath(): void
    {
        putenv('HOME');
        $testUserProfile = 'C:\\Users\\TestUser';
        $testHomeDrive = 'D:';
        $testHomePath = '\\Users\\Other';
        putenv('USERPROFILE=' . $testUserProfile);
        putenv('HOMEDRIVE=' . $testHomeDrive);
        putenv('HOMEPATH=' . $testHomePath);

        $trait = new class {
            use HomeDirectoryTrait;

            public function testGetHomeDirectory(): string
            {
                return $this->getHomeDirectory();
            }
        };

        $result = $trait->testGetHomeDirectory();
        $this->assertEquals($testUserProfile, $result);
    }
}
