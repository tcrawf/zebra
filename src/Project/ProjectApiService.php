<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Tcrawf\Zebra\Client\ZebraApiException;

/**
 * Service for fetching projects from an external API.
 * Implementation details will be defined subsequently.
 */
final class ProjectApiService implements ProjectApiServiceInterface
{
    // This gets both active, active and other project statuses.
    private const string API_URL = '/api/v2/projects?statuses[]=0&statuses[]=1&statuses[]=2';

    /**
     * @param ClientInterface $httpClient
     */
    public function __construct(
        private readonly ClientInterface $httpClient
    ) {
    }

    /**
     * Fetch all projects from the API (both active and inactive).
     * Always fetches projects with status 0 (inactive) and status 1 (active).
     * The API request is formatted as: ?statuses[]=0&statuses[]=1
     *
     * @return array<int, array<string, mixed>>
     * @throws ZebraApiException
     */
    public function fetchAll(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL);
            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new ZebraApiException('Failed to fetch projects from Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }

        if (!isset($data['data']['list']) || !is_array($data['data']['list'])) {
            return [];
        }

        $projects = [];
        foreach ($data['data']['list'] as $id => $project) {
            $projects[$id] = $project;
        }

        return $projects;
    }
}
