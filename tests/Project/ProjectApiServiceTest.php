<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Project;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\Project\ProjectApiService;

class ProjectApiServiceTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private ProjectApiService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->service = new ProjectApiService($this->httpClient);
    }

    public function testFetchAll(): void
    {
        $responseData = [
            'success' => true,
            'data' => [
                'list' => [
                    100 => [
                        'id' => 100,
                        'name' => 'Test Project',
                        'description' => 'Description',
                        'status' => 1,
                        'activities' => []
                    ]
                ]
            ]
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
            ->willReturn($response);

        $result = $this->service->fetchAll();

        $this->assertIsArray($result);
        $this->assertArrayHasKey(100, $result);
        $this->assertEquals('Test Project', $result[100]['name']);
    }

    public function testFetchAllWithEmptyList(): void
    {
        $responseData = [
            'success' => true,
            'data' => [
                'list' => []
            ]
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
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
            ->with('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
            ->willThrowException(
                new RequestException(
                    'Connection failed',
                    new Request('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
                )
            );

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to fetch projects from Zebra API');

        $this->service->fetchAll();
    }

    public function testFetchAllThrowsExceptionOnInvalidJson(): void
    {
        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->fetchAll();
    }

    public function testFetchAllThrowsExceptionWhenSuccessIsFalse(): void
    {
        $responseData = [
            'success' => false,
            'data' => []
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('API request was not successful');

        $this->service->fetchAll();
    }

    public function testFetchAllReturnsEmptyWhenListNotSet(): void
    {
        $responseData = [
            'success' => true,
            'data' => []
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
            ->willReturn($response);

        $result = $this->service->fetchAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFetchAllFetchesBothActiveAndInactive(): void
    {
        $responseData = [
            'success' => true,
            'data' => [
                'list' => [
                    100 => [
                        'id' => 100,
                        'name' => 'Active Project',
                        'description' => 'Description',
                        'status' => 1,
                        'activities' => []
                    ],
                    200 => [
                        'id' => 200,
                        'name' => 'Inactive Project',
                        'description' => 'Description',
                        'status' => 0,
                        'activities' => []
                    ]
                ]
            ]
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2')
            ->willReturn($response);

        $result = $this->service->fetchAll();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(100, $result);
        $this->assertArrayHasKey(200, $result);
    }
}
