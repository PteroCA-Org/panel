<?php

declare(strict_types=1);

namespace App\Core\Adapter\Pterodactyl\Client;

use App\Core\Contract\Pterodactyl\Client\PterodactylDatabasesInterface;
use App\Core\DTO\Pterodactyl\Client\PterodactylDatabase;
use App\Core\DTO\Pterodactyl\Collection;
use Exception;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class PterodactylDatabases extends AbstractPterodactylClientAdapter implements PterodactylDatabasesInterface
{
    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getDatabases(string $serverId, array $params = []): Collection
    {
        $options = [];
        if (!empty($params)) {
            $options['query'] = $params;
        }
        
        $response = $this->makeRequest('GET', "servers/$serverId/databases", $options);
        $data = $this->validateListResponse($response, 200);

        $items = array_map(
            fn(array $database): PterodactylDatabase => new PterodactylDatabase($database),
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
    public function createDatabase(string $serverId, string $database, string $remote): PterodactylDatabase
    {
        $response = $this->makeRequest('POST', "servers/$serverId/databases", [
            'json' => [
                'database' => $database,
                'remote' => $remote,
            ],
        ]);

        // Pterodactyl returns 200 for created resources; 201 is accepted for compatible panels
        $data = $this->validateClientResponse($response, [200, 201]);

        return new PterodactylDatabase($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function rotatePassword(string $serverId, string $databaseId): PterodactylDatabase
    {
        $response = $this->makeRequest('POST', "servers/$serverId/databases/$databaseId/rotate-password");
        $data = $this->validateClientResponse($response, 200);

        return new PterodactylDatabase($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function deleteDatabase(string $serverId, string $databaseId): void
    {
        $response = $this->makeRequest('DELETE', "servers/$serverId/databases/$databaseId");
        
        if ($response->getStatusCode() !== 204) {
            throw new Exception(
                sprintf('Pterodactyl Client API error: %d %s',
                    $response->getStatusCode(),
                    $response->getContent(false)
                )
            );
        }
    }
}
