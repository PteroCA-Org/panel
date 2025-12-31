<?php

namespace App\Core\Adapter\Pterodactyl\Application;

use App\Core\Contract\Pterodactyl\Application\PterodactylUsersInterface;
use App\Core\DTO\Pterodactyl\Application\PterodactylApiKey;
use App\Core\DTO\Pterodactyl\Application\PterodactylUser;
use App\Core\DTO\Pterodactyl\Collection;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class PterodactylUsers extends AbstractPterodactylApplicationAdapter implements PterodactylUsersInterface
{
    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getAllUsers(array $parameters = []): Collection
    {
        return $this->getUsersWithParameters($parameters);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getAllUsersPaginated(int $page = 1, array $parameters = []): Collection
    {
        $queryParams = array_merge(['page' => $page], $parameters);
        return $this->getUsersWithParameters($queryParams);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getUser(int|string $userId, array $parameters = []): PterodactylUser
    {
        $response = $this->makeRequest('GET', "users/$userId", ['query' => $parameters]);
        $data = $this->validateServerResponse($response, 200);
        
        return new PterodactylUser($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getUserByExternalId(string $externalId, array $parameters = []): PterodactylUser
    {
        $response = $this->makeRequest('GET', "users/external/$externalId", ['query' => $parameters]);
        $data = $this->validateServerResponse($response, 200);
        
        return new PterodactylUser($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function updateUser(int|string $userId, array $details): PterodactylUser
    {
        $response = $this->makeRequest('PATCH', "users/$userId", ['json' => $details]);
        $data = $this->validateServerResponse($response, 200);

        return new PterodactylUser($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function createUser(array $details): PterodactylUser
    {
        $response = $this->makeRequest('POST', 'users', ['json' => $details]);
        $data = $this->validateServerResponse($response, 201);

        return new PterodactylUser($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function deleteUser(int|string $userId): bool
    {
        $response = $this->makeRequest('DELETE', "users/$userId");
        return in_array($response->getStatusCode(), [200, 204]);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function createApiKeyForUser(int|string $userId, string $description): PterodactylApiKey
    {
        $response = $this->makeRequest('POST', "users/$userId/api-keys", [
            'json' => ['description' => $description, 'allowed_ips' => []]
        ]);
        $data = $this->validateServerResponse($response, 200);

        return new PterodactylApiKey($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * Delete API key for a user
     *
     * @param int|string $userId
     * @param string $identifier
     * @return bool
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function deleteApiKeyForUser(int|string $userId, string $identifier): bool
    {
        $response = $this->makeRequest('DELETE', "users/$userId/api-keys/$identifier");
        return $this->validateServerResponse($response, 204) !== null;
    }

    /**
     * @param array $parameters
     * @return Collection
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function getUsersWithParameters(array $parameters): Collection
    {
        $response = $this->makeRequest('GET', 'users', ['query' => $parameters]);
        $data = $this->validateServerResponse($response, 200);

        $users = [];
        foreach ($data['data'] as $item) {
            $users[] = new PterodactylUser($item['attributes']);
        }

        return new Collection($users, $this->getMetaFromResponse($data));
    }
}
