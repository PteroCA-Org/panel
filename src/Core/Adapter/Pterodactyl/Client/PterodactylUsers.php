<?php

namespace App\Core\Adapter\Pterodactyl\Client;

use App\Core\Contract\Pterodactyl\Client\PterodactylUsersInterface;
use App\Core\DTO\Pterodactyl\Client\PterodactylSubuser;
use App\Core\DTO\Pterodactyl\Collection;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class PterodactylUsers extends AbstractPterodactylClientAdapter implements PterodactylUsersInterface
{
    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getUsers(string $serverId): Collection
    {
        $response = $this->makeRequest('GET', "servers/$serverId/users");
        $data = $this->validateListResponse($response, 200);

        $items = array_map(
            fn(array $user) => new PterodactylSubuser($user['attributes']),
            $data['data'] ?? []
        );

        return new Collection($items, $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function createUser(string $serverId, string $email, array $permissions): PterodactylSubuser
    {
        $response = $this->makeRequest('POST', "servers/$serverId/users", [
            'json' => [
                'email' => $email,
                'permissions' => $permissions
            ]
        ]);
        
        // Pterodactyl returns 200 for created resources; 201 is accepted for compatible panels
        $data = $this->validateClientResponse($response, [200, 201]);
        return new PterodactylSubuser($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getUser(string $serverId, string $userId): PterodactylSubuser
    {
        $response = $this->makeRequest('GET', "servers/$serverId/users/$userId");
        $data = $this->validateClientResponse($response, 200);

        return new PterodactylSubuser($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function updateUserPermissions(string $serverId, string $userId, array $permissions): PterodactylSubuser
    {
        $response = $this->makeRequest('POST', "servers/$serverId/users/$userId", [
            'json' => [
                'permissions' => $permissions
            ]
        ]);
        
        $data = $this->validateClientResponse($response, 200);
        return new PterodactylSubuser($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function deleteUser(string $serverId, string $userId): void
    {
        $this->makeRequest('DELETE', "servers/$serverId/users/$userId");
    }
}
