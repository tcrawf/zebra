<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\User;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\User\UserApiService;

class UserApiServiceTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private UserApiService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->service = new UserApiService($this->httpClient);
    }

    public function testFetchAll(): void
    {
        $responseData = [
            'success' => true,
            'data' => [
                'list' => [
                    1 => ['id' => 1, 'username' => 'user1'],
                    2 => ['id' => 2, 'username' => 'user2'],
                ],
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/users')
            ->willReturn($response);

        $result = $this->service->fetchAll();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
    }

    public function testFetchAllReturnsEmptyArrayWhenListNotSet(): void
    {
        $responseData = [
            'success' => true,
            'data' => [],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->service->fetchAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFetchAllThrowsExceptionOnGuzzleException(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(
                new RequestException(
                    'Connection failed',
                    new Request('GET', '/api/v2/users')
                )
            );

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to fetch users from Zebra API');

        $this->service->fetchAll();
    }

    public function testFetchAllThrowsExceptionOnInvalidJson(): void
    {
        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/users')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->fetchAll();
    }

    public function testFetchAllThrowsExceptionWhenSuccessIsFalse(): void
    {
        $responseData = [
            'success' => false,
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('API request was not successful');

        $this->service->fetchAll();
    }

    public function testFetchById(): void
    {
        $id = 123;
        $responseData = [
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $id,
                    'username' => 'testuser',
                    'name' => 'Test User',
                ],
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/users/' . $id)
            ->willReturn($response);

        $result = $this->service->fetchById($id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertEquals($id, $result['user']['id']);
    }

    public function testFetchByIdThrowsExceptionWhenUserDataNotSet(): void
    {
        $id = 123;
        $responseData = [
            'success' => true,
            'data' => [],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('User data not found in API response');

        $this->service->fetchById($id);
    }

    public function testFetchByIdThrowsExceptionOnGuzzleException(): void
    {
        $id = 123;
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(
                new RequestException(
                    'Connection failed',
                    new Request('GET', '/api/v2/users/' . $id)
                )
            );

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to fetch user from Zebra API');

        $this->service->fetchById($id);
    }

    public function testFetchByIdThrowsExceptionOnInvalidJson(): void
    {
        $id = 123;
        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/users/' . $id)
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->fetchById($id);
    }
}
