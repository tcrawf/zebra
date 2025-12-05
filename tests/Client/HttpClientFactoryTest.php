<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tcrawf\Zebra\Client\HttpClientFactory;
use Tcrawf\Zebra\Version;

class HttpClientFactoryTest extends TestCase
{
    private string $originalToken;
    private string $originalBaseUri;
    private string $originalPhpunitRunning;

    protected function setUp(): void
    {
        // Store original ZEBRA_TOKEN value
        $this->originalToken = getenv('ZEBRA_TOKEN') ?: '';

        // Store original ZEBRA_BASE_URI value
        $this->originalBaseUri = getenv('ZEBRA_BASE_URI') ?: '';

        // Store original PHPUNIT_RUNNING value
        $this->originalPhpunitRunning = getenv('PHPUNIT_RUNNING') ?: '';

        // Set PHPUNIT_RUNNING to suppress warnings during tests
        putenv('PHPUNIT_RUNNING=1');
        $_SERVER['PHPUNIT_RUNNING'] = '1';
        $_ENV['PHPUNIT_RUNNING'] = '1';

        // Set default ZEBRA_BASE_URI for tests
        putenv('ZEBRA_BASE_URI=https://test.example.com');
        $_SERVER['ZEBRA_BASE_URI'] = 'https://test.example.com';
        $_ENV['ZEBRA_BASE_URI'] = 'https://test.example.com';
    }

    protected function tearDown(): void
    {
        // Restore original ZEBRA_TOKEN value
        if ($this->originalToken !== '') {
            putenv('ZEBRA_TOKEN=' . $this->originalToken);
            $_SERVER['ZEBRA_TOKEN'] = $this->originalToken;
            $_ENV['ZEBRA_TOKEN'] = $this->originalToken;
        } else {
            putenv('ZEBRA_TOKEN');
            unset($_SERVER['ZEBRA_TOKEN']);
            unset($_ENV['ZEBRA_TOKEN']);
        }

        // Restore original ZEBRA_BASE_URI value
        if ($this->originalBaseUri !== '') {
            putenv('ZEBRA_BASE_URI=' . $this->originalBaseUri);
            $_SERVER['ZEBRA_BASE_URI'] = $this->originalBaseUri;
            $_ENV['ZEBRA_BASE_URI'] = $this->originalBaseUri;
        } else {
            putenv('ZEBRA_BASE_URI');
            unset($_SERVER['ZEBRA_BASE_URI']);
            unset($_ENV['ZEBRA_BASE_URI']);
        }

        // Always restore PHPUNIT_RUNNING to '1' since it's set in bootstrap.php
        // This ensures warnings don't leak to other tests
        putenv('PHPUNIT_RUNNING=1');
        $_SERVER['PHPUNIT_RUNNING'] = '1';
        $_ENV['PHPUNIT_RUNNING'] = '1';
    }

    /**
     * Get the configuration from a Guzzle Client instance using reflection.
     *
     * @return array<string, mixed>
     */
    private function getClientConfig(ClientInterface $client): array
    {
        if (!($client instanceof Client)) {
            $this->fail('Client is not an instance of GuzzleHttp\Client');
        }

        $reflection = new ReflectionClass($client);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);

        return $configProperty->getValue($client);
    }

    public function testCreateWithToken(): void
    {
        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertEquals('https://test.example.com', $config['base_uri']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('application/json', $config['headers']['Accept']);
        $this->assertEquals('Bearer test_token_123', $config['headers']['Authorization']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateWithoutToken(): void
    {
        putenv('ZEBRA_TOKEN');
        unset($_SERVER['ZEBRA_TOKEN']);
        unset($_ENV['ZEBRA_TOKEN']);

        // Should not throw an exception, just warn (warning suppressed by PHPUNIT_RUNNING)
        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertEquals('https://test.example.com', $config['base_uri']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('application/json', $config['headers']['Accept']);
        $this->assertArrayNotHasKey('Authorization', $config['headers']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateWithEmptyToken(): void
    {
        putenv('ZEBRA_TOKEN=');
        $_SERVER['ZEBRA_TOKEN'] = '';
        $_ENV['ZEBRA_TOKEN'] = '';

        // Should not throw an exception, just warn (warning suppressed by PHPUNIT_RUNNING)
        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertEquals('https://test.example.com', $config['base_uri']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('application/json', $config['headers']['Accept']);
        $this->assertArrayNotHasKey('Authorization', $config['headers']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateWithCustomBaseUri(): void
    {
        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        $customUri = 'https://custom.example.com';
        $client = HttpClientFactory::create($customUri);

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertEquals($customUri, $config['base_uri']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('application/json', $config['headers']['Accept']);
        $this->assertEquals('Bearer test_token_123', $config['headers']['Authorization']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateWithCustomBaseUriWithoutToken(): void
    {
        putenv('ZEBRA_TOKEN');
        unset($_SERVER['ZEBRA_TOKEN']);
        unset($_ENV['ZEBRA_TOKEN']);

        $customUri = 'https://custom.example.com';
        $client = HttpClientFactory::create($customUri);

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertEquals($customUri, $config['base_uri']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('application/json', $config['headers']['Accept']);
        $this->assertArrayNotHasKey('Authorization', $config['headers']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateWithCustomBaseUriWithEmptyToken(): void
    {
        putenv('ZEBRA_TOKEN=');
        $_SERVER['ZEBRA_TOKEN'] = '';
        $_ENV['ZEBRA_TOKEN'] = '';

        $customUri = 'https://custom.example.com';
        $client = HttpClientFactory::create($customUri);

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertEquals($customUri, $config['base_uri']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('application/json', $config['headers']['Accept']);
        $this->assertArrayNotHasKey('Authorization', $config['headers']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateUsesEnvironmentBaseUriWhenNull(): void
    {
        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        putenv('ZEBRA_BASE_URI=https://test.example.com');
        $_SERVER['ZEBRA_BASE_URI'] = 'https://test.example.com';
        $_ENV['ZEBRA_BASE_URI'] = 'https://test.example.com';

        $client = HttpClientFactory::create(null);

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertEquals('https://test.example.com', $config['base_uri']);
    }

    public function testCreateWritesWarningWhenTokenMissingAndPhpunitNotRunning(): void
    {
        // Unset PHPUNIT_RUNNING to allow warning to be written
        putenv('PHPUNIT_RUNNING');
        unset($_SERVER['PHPUNIT_RUNNING']);
        unset($_ENV['PHPUNIT_RUNNING']);

        putenv('ZEBRA_TOKEN');
        unset($_SERVER['ZEBRA_TOKEN']);
        unset($_ENV['ZEBRA_TOKEN']);

        // The warning code path should execute without error
        // We verify the client is created successfully and configuration is correct
        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        // Verify configuration is correct
        $config = $this->getClientConfig($client);
        $this->assertArrayNotHasKey('Authorization', $config['headers']);
    }

    public function testCreateWritesWarningWhenTokenEmptyAndPhpunitNotRunning(): void
    {
        // Unset PHPUNIT_RUNNING to allow warning to be written
        putenv('PHPUNIT_RUNNING');
        unset($_SERVER['PHPUNIT_RUNNING']);
        unset($_ENV['PHPUNIT_RUNNING']);

        putenv('ZEBRA_TOKEN=');
        $_SERVER['ZEBRA_TOKEN'] = '';
        $_ENV['ZEBRA_TOKEN'] = '';

        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        // Verify configuration is correct even when warning is written
        $config = $this->getClientConfig($client);
        $this->assertArrayNotHasKey('Authorization', $config['headers']);
    }

    public function testCreateDoesNotWriteWarningWhenPhpunitRunning(): void
    {
        // Ensure PHPUNIT_RUNNING is set
        putenv('PHPUNIT_RUNNING=1');
        $_SERVER['PHPUNIT_RUNNING'] = '1';
        $_ENV['PHPUNIT_RUNNING'] = '1';

        putenv('ZEBRA_TOKEN');
        unset($_SERVER['ZEBRA_TOKEN']);
        unset($_ENV['ZEBRA_TOKEN']);

        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        // Verify no Authorization header is set
        $config = $this->getClientConfig($client);
        $this->assertArrayNotHasKey('Authorization', $config['headers']);
    }

    public function testCreateWithVariousTokenValues(): void
    {
        $testTokens = [
            'simple_token',
            'token-with-dashes',
            'token_with_underscores',
            'token123',
            'Bearer token123', // Should be prepended with Bearer again
            'very-long-token-' . str_repeat('a', 100),
        ];

        foreach ($testTokens as $token) {
            putenv('ZEBRA_TOKEN=' . $token);
            $_SERVER['ZEBRA_TOKEN'] = $token;
            $_ENV['ZEBRA_TOKEN'] = $token;

            $client = HttpClientFactory::create();
            $config = $this->getClientConfig($client);

            $this->assertEquals('Bearer ' . $token, $config['headers']['Authorization'], "Failed for token: {$token}");
            $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
        }
    }

    public function testCreateWritesWarningWhenBaseUriMissingAndPhpunitNotRunning(): void
    {
        // Unset PHPUNIT_RUNNING to allow warning to be written
        putenv('PHPUNIT_RUNNING');
        unset($_SERVER['PHPUNIT_RUNNING']);
        unset($_ENV['PHPUNIT_RUNNING']);

        putenv('ZEBRA_BASE_URI');
        unset($_SERVER['ZEBRA_BASE_URI']);
        unset($_ENV['ZEBRA_BASE_URI']);

        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        // The warning code path should execute without error
        // We verify the client is created successfully and configuration is correct
        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        // Verify configuration is correct (base_uri should be empty string)
        $config = $this->getClientConfig($client);
        $this->assertEquals('', $config['base_uri']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateWritesWarningWhenBaseUriEmptyAndPhpunitNotRunning(): void
    {
        // Unset PHPUNIT_RUNNING to allow warning to be written
        putenv('PHPUNIT_RUNNING');
        unset($_SERVER['PHPUNIT_RUNNING']);
        unset($_ENV['PHPUNIT_RUNNING']);

        putenv('ZEBRA_BASE_URI=');
        $_SERVER['ZEBRA_BASE_URI'] = '';
        $_ENV['ZEBRA_BASE_URI'] = '';

        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        // Verify configuration is correct even when warning is written
        $config = $this->getClientConfig($client);
        $this->assertEquals('', $config['base_uri']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateDoesNotWriteWarningWhenBaseUriSetAndPhpunitRunning(): void
    {
        // Ensure PHPUNIT_RUNNING is set
        putenv('PHPUNIT_RUNNING=1');
        $_SERVER['PHPUNIT_RUNNING'] = '1';
        $_ENV['PHPUNIT_RUNNING'] = '1';

        putenv('ZEBRA_BASE_URI=https://test.example.com');
        $_SERVER['ZEBRA_BASE_URI'] = 'https://test.example.com';
        $_ENV['ZEBRA_BASE_URI'] = 'https://test.example.com';

        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        // Verify base_uri is set correctly
        $config = $this->getClientConfig($client);
        $this->assertEquals('https://test.example.com', $config['base_uri']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateWithCustomBaseUriOverridesEnvironment(): void
    {
        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        putenv('ZEBRA_BASE_URI=https://other.example.com');
        $_SERVER['ZEBRA_BASE_URI'] = 'https://other.example.com';
        $_ENV['ZEBRA_BASE_URI'] = 'https://other.example.com';

        $customUri = 'https://custom.example.com';
        $client = HttpClientFactory::create($customUri);

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        // Custom URI should override environment variable
        $this->assertEquals($customUri, $config['base_uri']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }

    public function testCreateSetsUserAgentHeader(): void
    {
        putenv('ZEBRA_TOKEN=test_token_123');
        $_SERVER['ZEBRA_TOKEN'] = 'test_token_123';
        $_ENV['ZEBRA_TOKEN'] = 'test_token_123';

        $client = HttpClientFactory::create();

        $this->assertInstanceOf(ClientInterface::class, $client);

        $config = $this->getClientConfig($client);
        $this->assertArrayHasKey('headers', $config);
        $this->assertArrayHasKey('User-Agent', $config['headers']);
        $this->assertEquals('App zebra-cli(' . Version::getVersion() . ')', $config['headers']['User-Agent']);
    }
}
