<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

final class HttpClientFactory
{
    public static function create(string|null $baseUri = null): ClientInterface
    {
        if ($baseUri === null) {
            $baseUri = getenv('ZEBRA_BASE_URI');
            if ($baseUri === false || $baseUri === '') {
                // Suppress warnings in test environment
                if (getenv('PHPUNIT_RUNNING') === false) {
                    fwrite(
                        STDERR,
                        "Warning: ZEBRA_BASE_URI environment variable is not set. API requests may fail.\n" .
                        "Please set your Zebra API base URI using: export ZEBRA_BASE_URI=your_base_uri_here\n" .
                        "Or add it to your .env file: ZEBRA_BASE_URI=your_base_uri_here\n\n"
                    );
                }
                // No fallback - set to empty string so Guzzle will fail if used
                $baseUri = '';
            }
        }
        $token = getenv('ZEBRA_TOKEN');

        $headers = [
            'Accept' => 'application/json',
        ];

        if ($token === false || $token === '') {
            // Suppress warnings in test environment
            if (getenv('PHPUNIT_RUNNING') === false) {
                fwrite(
                    STDERR,
                    "Warning: ZEBRA_TOKEN environment variable is not set. API requests may fail.\n" .
                    "Please set your Zebra API token using: export ZEBRA_TOKEN=your_token_here\n" .
                    "Or add it to your .env file: ZEBRA_TOKEN=your_token_here\n\n"
                );
            }
        } else {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $config = [
            'base_uri' => $baseUri,
            'headers' => $headers,
        ];

        return new Client($config);
    }
}
