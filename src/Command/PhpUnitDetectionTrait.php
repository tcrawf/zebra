<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

/**
 * Trait for detecting if PHPUnit is currently running.
 *
 * Uses multiple detection methods for maximum reliability:
 * 1. PHPUnit-defined constants (PHPUNIT_COMPOSER_INSTALL, __PHPUNIT_PHAR__)
 * 2. Environment variables (PHPUNIT_RUNNING, PHPUNIT)
 * 3. PHPUnit TestCase class existence check
 * 4. STDOUT TTY check (CommandTester makes STDOUT non-TTY)
 */
trait PhpUnitDetectionTrait
{
    /**
     * Detect if PHPUnit is currently running.
     *
     * @return bool True if PHPUnit is running, false otherwise
     */
    private function isPhpUnitRunning(): bool
    {
        // Method 1: Check for PHPUnit-defined constants
        // These are set by PHPUnit itself when it runs, most reliable indicator
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            return true;
        }

        // Method 2: Check environment variables (set in tests/bootstrap.php)
        $phpunitRunning = getenv('PHPUNIT_RUNNING');
        if ($phpunitRunning === false) {
            $phpunitRunning = $_ENV['PHPUNIT_RUNNING'] ?? $_SERVER['PHPUNIT_RUNNING'] ?? false;
        }
        if ($phpunitRunning !== false && $phpunitRunning !== '' && $phpunitRunning !== '0') {
            return true;
        }

        $phpunit = getenv('PHPUNIT');
        if ($phpunit === false) {
            $phpunit = $_ENV['PHPUNIT'] ?? $_SERVER['PHPUNIT'] ?? false;
        }
        if ($phpunit !== false && $phpunit !== '' && $phpunit !== '0') {
            return true;
        }

        // Method 3: Check if PHPUnit TestCase class exists
        // This works even if constants aren't set
        if (class_exists(\PHPUnit\Framework\TestCase::class, false)) {
            return true;
        }

        // Method 4: Check if STDOUT is not a TTY
        // CommandTester captures output, making STDOUT non-TTY in test environments
        $isStdoutTty = function_exists('stream_isatty')
            && defined('STDOUT')
            && is_resource(STDOUT)
            && @stream_isatty(STDOUT);

        if (!$isStdoutTty) {
            return true;
        }

        return false;
    }
}
