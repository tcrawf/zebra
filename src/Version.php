<?php

declare(strict_types=1);

namespace Tcrawf\Zebra;

/**
 * Application version information.
 */
final class Version
{
    public const string VERSION = '1.0.0';

    /**
     * Get the current application version.
     *
     * @return string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
