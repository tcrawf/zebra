<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\User;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Tcrawf\Zebra\Client\ZebraApiException;

/**
 * Service for fetching users from an external API.
 */
final class UserApiService implements UserApiServiceInterface
{
    private const string API_URL = '/api/v2/users';

    /**
     * @param ClientInterface $httpClient
     */
    public function __construct(
        private readonly ClientInterface $httpClient
    ) {
    }

    /**
     * Fetch all users from the API.
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
            throw new ZebraApiException('Failed to fetch users from Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }

        if (!isset($data['data']['list']) || !is_array($data['data']['list'])) {
            return [];
        }

        $users = [];
        foreach ($data['data']['list'] as $id => $user) {
            $users[$id] = $user;
        }

        return $users;
    }

    /**
     * Fetch a single user by ID with detailed information including roles.
     *
     * @param int $id
     * @return array<string, mixed>
     * @throws ZebraApiException
     */
    public function fetchById(int $id): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL . '/' . $id);
            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new ZebraApiException('Failed to fetch user from Zebra API: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new ZebraApiException('Failed to decode JSON response from Zebra API: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new ZebraApiException('API request was not successful');
        }

        if (!isset($data['data']['user']) || !is_array($data['data']['user'])) {
            throw new ZebraApiException('User data not found in API response');
        }

        return $data['data'];
    }
}
