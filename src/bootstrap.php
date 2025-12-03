<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Load .env file from project root
// When running as PHAR, load from current working directory instead
$pharPath = Phar::running(false);
if ($pharPath) {
    // Running as PHAR: load .env from current working directory
    $dotenv = Dotenv::createUnsafeImmutable(getcwd());
} else {
    // Normal execution: load .env from project root
    $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
}
// safeLoad() won't override existing environment variables
// This allows ZEBRA_TOKEN and TZ from shell environment to take precedence
// Note: safeLoad() populates $_SERVER and makes variables available via getenv()
// but may not populate $_ENV depending on PHP configuration
$dotenv->safeLoad();

// Get TZ value - dotenv makes it available via getenv() and $_SERVER
// Priority: $_SERVER (from dotenv) > getenv() > $_ENV
$tz = $_SERVER['TZ'] ?? getenv('TZ') ?: ($_ENV['TZ'] ?? null);

// Default TZ to Europe/Zurich if not set, but warn
if ($tz === null || $tz === '') {
    $tz = 'Europe/Zurich';
    fwrite(
        STDERR,
        "Warning: TZ environment variable is not set. Defaulting to Europe/Zurich.\n" .
        "Please set your timezone using: export TZ=Europe/Zurich\n" .
        "Or add it to your .env file: TZ=Europe/Zurich\n" .
        "See https://en.wikipedia.org/wiki/List_of_tz_database_time_zones for valid timezones.\n\n"
    );
}

// Ensure TZ is available in all PHP environment variable arrays for consistency
if (!isset($_SERVER['TZ'])) {
    $_SERVER['TZ'] = $tz;
}
if (!isset($_ENV['TZ'])) {
    $_ENV['TZ'] = $tz;
}
// Ensure getenv() can access it
if (getenv('TZ') === false) {
    putenv('TZ=' . $tz);
}

// Validate TZ is a valid timezone identifier
try {
    new DateTimeZone($tz);
} catch (Exception $e) {
    fwrite(
        STDERR,
        "Error: Invalid timezone '{$tz}' specified in TZ environment variable.\n" .
        "Please set a valid timezone using: export TZ=Europe/Zurich\n" .
        "Or add it to your .env file: TZ=Europe/Zurich\n" .
        "See https://en.wikipedia.org/wiki/List_of_tz_database_time_zones for valid timezones.\n"
    );
    exit(1);
}

// Set PHP's default timezone to match TZ environment variable
// This ensures consistency across the application
date_default_timezone_set($tz);

// Enable debugger if Xdebug is available
// This works for both PHAR and direct bin execution
if (extension_loaded('xdebug')) {
    // Check if XDEBUG_MODE is explicitly set
    $xdebugMode = getenv('XDEBUG_MODE');
    $iniMode = ini_get('xdebug.mode');

    // Determine the current mode (environment variable takes precedence)
    $currentMode = $xdebugMode !== false && $xdebugMode !== '' ? $xdebugMode : $iniMode;

    // Only enable debug mode if not explicitly set or set to empty
    // Respect explicit 'off' or '0' values to allow disabling
    if ($xdebugMode === false || $xdebugMode === '') {
        // If current mode doesn't include 'debug', add it
        $modes = ($currentMode !== false && $currentMode !== '') ? explode(',', $currentMode) : [];
        if (!in_array('debug', $modes, true) && !in_array('off', $modes, true)) {
            if (count($modes) > 0) {
                $modes[] = 'debug';
                $newMode = implode(',', $modes);
                putenv('XDEBUG_MODE=' . $newMode);
                $_SERVER['XDEBUG_MODE'] = $newMode;
                $_ENV['XDEBUG_MODE'] = $newMode;

                // Try to set ini setting (may not work for all xdebug settings)
                @ini_set('xdebug.mode', $newMode);
            } else {
                // No mode set at all, default to 'debug'
                putenv('XDEBUG_MODE=debug');
                $_SERVER['XDEBUG_MODE'] = 'debug';
                $_ENV['XDEBUG_MODE'] = 'debug';
                @ini_set('xdebug.mode', 'debug');
            }
        }
    }

    // Note: For CLI debugging, XDEBUG_SESSION_START must be set as an environment
    // variable BEFORE PHP starts (not inside this script). Use:
    //   XDEBUG_MODE=debug XDEBUG_SESSION_START=1 bin/zebra
    // Or use the bin/track-debug wrapper script for convenience.
}

// Warn if xdebug is enabled (it significantly slows down CLI applications)
// Allow disabling the warning via ZEBRA_SILENT_XDEBUG_WARNING environment variable
// Don't warn if XDEBUG_MODE is explicitly set to 'debug' (intentional debugging)
if (extension_loaded('xdebug') && !getenv('ZEBRA_SILENT_XDEBUG_WARNING')) {
    $xdebugMode = getenv('XDEBUG_MODE') ?: ini_get('xdebug.mode');
    // Only warn if xdebug is in a mode that affects performance (not just 'off' or intentionally 'debug')
    // If XDEBUG_MODE is explicitly set to 'debug', assume it's intentional for debugging
    if ($xdebugMode !== 'off' && $xdebugMode !== false && $xdebugMode !== '' && $xdebugMode !== 'debug') {
        fwrite(
            STDERR,
            "Warning: Xdebug is enabled (mode: {$xdebugMode}). This may significantly slow down the application.\n" .
            "Consider disabling Xdebug for better performance: php -d xdebug.mode=off bin/zebra\n" .
            "Or set ZEBRA_SILENT_XDEBUG_WARNING=1 to suppress this warning.\n\n"
        );
    }
}
