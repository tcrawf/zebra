<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Timesheet;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;
use Tcrawf\Zebra\Client\ZebraApiException;

/**
 * Service for interacting with the Zebra timesheet API.
 */
final class TimesheetApiService implements TimesheetApiServiceInterface
{
    private const string API_URL = '/api/v2/timesheets';

    /**
     * @param ClientInterface $httpClient
     */
    public function __construct(
        private readonly ClientInterface $httpClient
    ) {
    }

    /**
     * Create a new timesheet entry via POST.
     *
     * @param array<string, mixed> $data Timesheet data
     * @return array<string, mixed> API response data
     * @throws ZebraApiException
     */
    public function create(array $data): array
    {
        $this->validateCreateData($data);

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'query' => $this->formatQueryParams($data),
            ]);
            $body = (string) $response->getBody();
            $responseData = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new ZebraApiException('Failed to create timesheet via Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($responseData['success']) || $responseData['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }

        // POST returns null on success, but we can return the response data
        return $responseData;
    }

    /**
     * Update an existing timesheet via PUT.
     *
     * @param int $id The Zebra timesheet ID
     * @param array<string, mixed> $data Timesheet data to update
     * @return array<string, mixed> API response data
     * @throws ZebraApiException
     */
    public function update(int $id, array $data): array
    {
        $this->validateUpdateData($data);

        try {
            $response = $this->httpClient->request('PUT', self::API_URL . '/' . $id, [
                'query' => $this->formatQueryParams($data),
            ]);
            $body = (string) $response->getBody();
            $responseData = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new ZebraApiException('Failed to update timesheet via Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($responseData['success']) || $responseData['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }

        // PUT returns null on success, but we can return the response data
        return $responseData;
    }

    /**
     * Delete a timesheet via DELETE.
     *
     * @param int $id The Zebra timesheet ID
     * @return void
     * @throws ZebraApiException
     */
    public function delete(int $id): void
    {
        try {
            $response = $this->httpClient->request('DELETE', self::API_URL . '/' . $id);
            $body = (string) $response->getBody();
            $responseData = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new ZebraApiException('Failed to delete timesheet via Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($responseData['success']) || $responseData['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }
    }

    /**
     * Fetch all timesheets with optional filters.
     *
     * @param array<string, mixed> $filters Optional filters
     * @return array<int, array<string, mixed>> Array of timesheet data indexed by ID
     * @throws ZebraApiException
     */
    public function fetchAll(array $filters = []): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => $this->formatQueryParams($filters),
            ]);
            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new ZebraApiException('Failed to fetch timesheets from Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }

        if (!isset($data['data']['list']) || !is_array($data['data']['list'])) {
            return [];
        }

        $timesheets = [];
        foreach ($data['data']['list'] as $timesheet) {
            if (isset($timesheet['id']) && is_int($timesheet['id'])) {
                $timesheets[$timesheet['id']] = $timesheet;
            }
        }

        return $timesheets;
    }

    /**
     * Fetch a single timesheet by ID.
     *
     * @param int $id The Zebra timesheet ID
     * @return array<string, mixed> Timesheet data
     * @throws ZebraApiException
     */
    public function fetchById(int $id): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL . '/' . $id);
            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Check for 404 specifically
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                throw new ZebraApiException(
                    sprintf('Timesheet with ID %d not found (404)', $id),
                    404,
                    $e
                );
            }
            throw new ZebraApiException('Failed to fetch timesheet from Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            throw new ZebraApiException('Failed to fetch timesheet from Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }

        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new ZebraApiException('Timesheet data not found in API response');
        }

        return $data['data'];
    }

    /**
     * Validate data for create operation.
     *
     * @param array<string, mixed> $data
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateCreateData(array $data): void
    {
        $requiredFields = ['project_id', 'activity_id', 'description', 'time', 'date'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate time is a multiple of 0.25
        if (isset($data['time']) && is_numeric($data['time'])) {
            $time = (float) $data['time'];
            $remainder = fmod($time * 100, 25);
            if (abs($remainder) > 0.0001) {
                throw new InvalidArgumentException("Time must be a multiple of 0.25, got: {$time}");
            }
        }
    }

    /**
     * Validate data for update operation.
     *
     * @param array<string, mixed> $data
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateUpdateData(array $data): void
    {
        // For updates, fields are optional, but if provided, validate them
        if (isset($data['time']) && is_numeric($data['time'])) {
            $time = (float) $data['time'];
            $remainder = fmod($time * 100, 25);
            if (abs($remainder) > 0.0001) {
                throw new InvalidArgumentException("Time must be a multiple of 0.25, got: {$time}");
            }
        }
    }

    /**
     * Format data as query parameters for API requests.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function formatQueryParams(array $data): array
    {
        $params = [];

        // Handle array parameters (users[], projects[], etc.)
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // For array values, format as array notation
                foreach ($value as $item) {
                    $params[$key . '[]'] = $item;
                }
            } elseif ($value !== null) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
