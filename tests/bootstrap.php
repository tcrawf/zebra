<?php

declare(strict_types=1);

// Set TZ environment variable for tests before bootstrap.php is loaded
// This ensures bootstrap.php validation passes
// Always set it for tests to ensure consistency
putenv('TZ=UTC');
$_ENV['TZ'] = 'UTC';
$_SERVER['TZ'] = 'UTC';

// Set PHPUNIT_RUNNING to suppress warnings during tests
// This must be set before any code that might trigger warnings (e.g., HttpClientFactory)
putenv('PHPUNIT_RUNNING=1');
$_ENV['PHPUNIT_RUNNING'] = '1';
$_SERVER['PHPUNIT_RUNNING'] = '1';

// Suppress stty errors in test environment
// Symfony Console's ChoiceQuestion uses stty to detect terminal capabilities
// In non-interactive test environments, this can fail and produce error messages
// We create a wrapper script that suppresses stderr but allows stty to function
// This prevents error messages while maintaining compatibility with interactive questions
// Store original PATH to restore it later
$originalPath = getenv('PATH') ?: '';
$sttyWrapperDir = null;
if (function_exists('proc_open') && PHP_OS_FAMILY !== 'Windows') {
    $sttyPath = trim(shell_exec('which stty 2>/dev/null') ?: '');
    if ($sttyPath !== '' && is_executable($sttyPath)) {
        // Create a wrapper script that suppresses stderr but executes stty normally
        // This allows stty to work for terminal detection while hiding error messages
        $sttyWrapperDir = sys_get_temp_dir() . '/stty_wrapper_' . uniqid();
        mkdir($sttyWrapperDir, 0755, true);
        $sttyWrapper = $sttyWrapperDir . '/stty';
        $wrapperContent = "#!/bin/sh\n" .
            "# Wrapper to suppress stty errors in test environment\n" .
            "{$sttyPath} \"\$@\" 2>/dev/null\n" .
            "exit \$?\n";
        file_put_contents($sttyWrapper, $wrapperContent);
        chmod($sttyWrapper, 0755);
        // Prepend wrapper directory to PATH so it's found before the real stty
        putenv('PATH=' . $sttyWrapperDir . ':' . $originalPath);
        $_SERVER['PATH'] = $sttyWrapperDir . ':' . $originalPath;
        $_ENV['PATH'] = $sttyWrapperDir . ':' . $originalPath;
    }
}

// Register shutdown function to restore original PATH and terminal state
register_shutdown_function(function () use ($originalPath, &$sttyWrapperDir) {
    // Restore original PATH in all environment arrays
    if ($originalPath !== '') {
        putenv('PATH=' . $originalPath);
        $_SERVER['PATH'] = $originalPath;
        $_ENV['PATH'] = $originalPath;
    } else {
        // If original was empty, unset PATH (let system use default)
        // Note: We can't reliably get the "original" system PATH after modification,
        // so we'll just unset it and let the shell use its default
        putenv('PATH');
        unset($_SERVER['PATH']);
        unset($_ENV['PATH']);
    }

    // Restore terminal to a sane state if stty is available
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
        $sttyPath = trim(shell_exec('which stty 2>/dev/null') ?: '');
        if ($sttyPath !== '' && is_executable($sttyPath)) {
            // Reset terminal to sane defaults (sane mode)
            @shell_exec("{$sttyPath} sane 2>/dev/null");
        }
    }

    // Clean up wrapper directory (best effort, may fail if tests are still running)
    if ($sttyWrapperDir !== null && is_dir($sttyWrapperDir)) {
        @unlink($sttyWrapperDir . '/stty');
        @rmdir($sttyWrapperDir);
    }
});

// Now load the autoloader which will trigger bootstrap.php
require __DIR__ . '/../vendor/autoload.php';
