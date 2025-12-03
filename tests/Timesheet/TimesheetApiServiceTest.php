<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Timesheet;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\Timesheet\TimesheetApiService;

class TimesheetApiServiceTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private TimesheetApiService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->service = new TimesheetApiService($this->httpClient);
    }

    public function testCreate(): void
    {
        $requestData = [
            'project_id' => 100,
            'activity_id' => 200,
            'description' => 'Test description',
            'time' => 2.5,
            'date' => '2024-01-15',
        ];

        $responseData = [
            'success' => true,
            'data' => [
                'parameters' => $requestData,
                'timesheet' => [
                    'id' => 12345,
                    'date' => $requestData['date'],
                    'time' => $requestData['time'],
                    'projectid' => $requestData['project_id'],
                    'occupid' => $requestData['activity_id'],
                    'description' => $requestData['description'],
                    'lu_date' => '2024-01-15 10:00:00',
                ],
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/api/v2/timesheets',
                $this->callback(function ($options) use ($requestData) {
                    return isset($options['query'])
                        && $options['query']['project_id'] === $requestData['project_id']
                        && $options['query']['activity_id'] === $requestData['activity_id']
                        && $options['query']['description'] === $requestData['description']
                        && $options['query']['time'] === $requestData['time']
                        && $options['query']['date'] === $requestData['date'];
                })
            )
            ->willReturn($response);

        $result = $this->service->create($requestData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function testCreateWithOptionalFields(): void
    {
        $requestData = [
            'project_id' => 100,
            'activity_id' => 200,
            'description' => 'Test description',
            'client_description' => 'Client description',
            'time' => 1.25,
            'date' => '2024-01-15',
            'role_id' => 5,
        ];

        $responseData = [
            'success' => true,
            'data' => [
                'parameters' => $requestData,
                'timesheet' => [
                    'id' => 12346,
                    'date' => $requestData['date'],
                    'time' => $requestData['time'],
                    'projectid' => $requestData['project_id'],
                    'occupid' => $requestData['activity_id'],
                    'description' => $requestData['description'],
                    'client_description' => $requestData['client_description'],
                    'role_id' => $requestData['role_id'],
                    'lu_date' => '2024-01-15 10:00:00',
                ],
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->service->create($requestData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function testCreateThrowsExceptionOnMissingRequiredField(): void
    {
        $requestData = [
            'project_id' => 100,
            // Missing activity_id
            'description' => 'Test description',
            'time' => 2.5,
            'date' => '2024-01-15',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: activity_id');

        $this->service->create($requestData);
    }

    public function testCreateThrowsExceptionOnInvalidTime(): void
    {
        $requestData = [
            'project_id' => 100,
            'activity_id' => 200,
            'description' => 'Test description',
            'time' => 2.3, // Not a multiple of 0.25
            'date' => '2024-01-15',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Time must be a multiple of 0.25');

        $this->service->create($requestData);
    }

    public function testCreateThrowsExceptionOnGuzzleException(): void
    {
        $requestData = [
            'project_id' => 100,
            'activity_id' => 200,
            'description' => 'Test description',
            'time' => 2.5,
            'date' => '2024-01-15',
        ];

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(
                new RequestException(
                    'Connection failed',
                    new Request('POST', '/api/v2/timesheets')
                )
            );

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to create timesheet via Zebra API');

        $this->service->create($requestData);
    }

    public function testCreateThrowsExceptionOnInvalidJson(): void
    {
        $requestData = [
            'project_id' => 100,
            'activity_id' => 200,
            'description' => 'Test description',
            'time' => 2.5,
            'date' => '2024-01-15',
        ];

        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->create($requestData);
    }

    public function testUpdate(): void
    {
        $id = 12345;
        $requestData = [
            'description' => 'Updated description',
            'time' => 3.0,
        ];

        $responseData = [
            'success' => true,
            'data' => null,
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                '/api/v2/timesheets/' . $id,
                $this->callback(function ($options) use ($requestData) {
                    return isset($options['query'])
                        && $options['query']['description'] === $requestData['description']
                        && $options['query']['time'] === $requestData['time'];
                })
            )
            ->willReturn($response);

        $result = $this->service->update($id, $requestData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function testUpdateThrowsExceptionOnInvalidTime(): void
    {
        $id = 12345;
        $requestData = [
            'time' => 2.3, // Not a multiple of 0.25
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Time must be a multiple of 0.25');

        $this->service->update($id, $requestData);
    }

    public function testUpdateThrowsExceptionOnInvalidJson(): void
    {
        $id = 12345;
        $requestData = [
            'description' => 'Updated description',
            'time' => 3.0,
        ];

        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->update($id, $requestData);
    }

    public function testDelete(): void
    {
        $id = 12345;

        $responseData = [
            'success' => true,
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('DELETE', '/api/v2/timesheets/' . $id)
            ->willReturn($response);

        $this->service->delete($id);

        // If no exception is thrown, test passes
        $this->assertTrue(true);
    }

    public function testDeleteThrowsExceptionOnGuzzleException(): void
    {
        $id = 12345;

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(
                new RequestException(
                    'Connection failed',
                    new Request('DELETE', '/api/v2/timesheets/' . $id)
                )
            );

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to delete timesheet via Zebra API');

        $this->service->delete($id);
    }

    public function testDeleteThrowsExceptionOnInvalidJson(): void
    {
        $id = 12345;

        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('DELETE', '/api/v2/timesheets/' . $id)
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->delete($id);
    }

    public function testFetchAll(): void
    {
        $responseData = [
            'success' => true,
            'data' => [
                'list' => [
                    [
                        'id' => 12345,
                        'date' => '2024-01-15',
                        'occupation_id' => 200,
                        'project_id' => 100,
                        'description' => 'Test description',
                        'time' => 2.5,
                        'lu_date' => '2024-01-15 10:00:00',
                    ],
                    [
                        'id' => 12346,
                        'date' => '2024-01-16',
                        'occupation_id' => 201,
                        'project_id' => 101,
                        'description' => 'Another description',
                        'time' => 1.25,
                        'lu_date' => '2024-01-16 11:00:00',
                    ],
                ],
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/timesheets', ['query' => []])
            ->willReturn($response);

        $result = $this->service->fetchAll();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(12345, $result);
        $this->assertArrayHasKey(12346, $result);
    }

    public function testFetchAllWithFilters(): void
    {
        $filters = [
            'start_date' => '2024-01-15',
            'end_date' => '2024-01-20',
            'users' => [486],
        ];

        $responseData = [
            'success' => true,
            'data' => [
                'list' => [],
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/api/v2/timesheets',
                $this->callback(function ($options) use ($filters) {
                    $query = $options['query'];
                    return isset($query['start_date'])
                        && isset($query['end_date'])
                        && isset($query['users[]']);
                })
            )
            ->willReturn($response);

        $result = $this->service->fetchAll($filters);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFetchAllReturnsEmptyWhenListNotSet(): void
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

    public function testFetchAllThrowsExceptionOnInvalidJson(): void
    {
        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/timesheets', ['query' => []])
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->fetchAll();
    }

    public function testFetchById(): void
    {
        $id = 12345;
        $responseData = [
            'success' => true,
            'data' => [
                'id' => $id,
                'date' => '2024-01-15',
                'occupation_id' => 200,
                'project_id' => 100,
                'description' => 'Test description',
                'time' => 2.5,
                'lu_date' => '2024-01-15 10:00:00',
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/timesheets/' . $id)
            ->willReturn($response);

        $result = $this->service->fetchById($id);

        $this->assertIsArray($result);
        $this->assertEquals($id, $result['id']);
        $this->assertEquals('Test description', $result['description']);
    }

    public function testFetchByIdThrowsExceptionWhenDataNotSet(): void
    {
        $id = 12345;
        $responseData = [
            'success' => true,
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Timesheet data not found in API response');

        $this->service->fetchById($id);
    }

    public function testFetchByIdThrowsExceptionOnInvalidJson(): void
    {
        $id = 12345;
        $response = new Response(200, [], 'invalid json');
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v2/timesheets/' . $id)
            ->willReturn($response);

        $this->expectException(ZebraApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->service->fetchById($id);
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
}
