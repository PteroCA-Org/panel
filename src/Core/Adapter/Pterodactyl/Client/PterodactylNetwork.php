<?php

namespace App\Core\Adapter\Pterodactyl\Client;

use App\Core\Contract\Pterodactyl\Client\PterodactylNetworkInterface;
use App\Core\DTO\Pterodactyl\Client\PterodactylAllocation;
use App\Core\DTO\Pterodactyl\Collection;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class PterodactylNetwork extends AbstractPterodactylClientAdapter implements PterodactylNetworkInterface
{
    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getAllocations(string $serverId): Collection
    {
        $response = $this->makeRequest('GET', "servers/$serverId/network/allocations");
        $data = $this->validateListResponse($response, 200);

        $items = [];
        foreach ($data['data'] as $allocationData) {
            $items[] = new PterodactylAllocation($allocationData['attributes']);
        }

        return new Collection($items, $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function assignAllocation(string $serverId, ?string $ip = null, ?int $port = null): PterodactylAllocation
    {
        $payload = [];
        if ($ip !== null) {
            $payload['ip'] = $ip;
        }
        if ($port !== null) {
            $payload['port'] = $port;
        }

        $response = $this->makeRequest('POST', "servers/$serverId/network/allocations", ['json' => $payload]);
        // Pterodactyl returns 200 for created resources; 201 is accepted for compatible panels
        $data = $this->validateClientResponse($response, [200, 201]);
        
        return new PterodactylAllocation($this->getDataFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function setPrimaryAllocation(string $serverId, int $allocationId): PterodactylAllocation
    {
        $response = $this->makeRequest('POST', "servers/$serverId/network/allocations/$allocationId/primary");
        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            // API returns data
            $data = $this->validateClientResponse($response, 200);
            return new PterodactylAllocation($this->getDataFromResponse($data));
        }

        // If no data returned, fetch allocations and find the one we just set as primary
        $allocations = $this->getAllocations($serverId);
        foreach ($allocations->getItems() as $allocation) {
            if ($allocation->get('id') === $allocationId) {
                return $allocation;
            }
        }

        throw new \RuntimeException('Failed to retrieve updated allocation');
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function updateAllocationNotes(string $serverId, int $allocationId, string $notes): PterodactylAllocation
    {
        $response = $this->makeRequest('POST', "servers/$serverId/network/allocations/$allocationId", [
            'json' => ['notes' => $notes]
        ]);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            // API returns data
            $data = $this->validateClientResponse($response, 200);
            return new PterodactylAllocation($this->getDataFromResponse($data));
        }

        if ($statusCode === 204) {
            // If no data returned, fetch allocations and find the updated one
            $allocations = $this->getAllocations($serverId);
            foreach ($allocations->getItems() as $allocation) {
                if ($allocation->get('id') === $allocationId) {
                    return $allocation;
                }
            }
        }

        throw new \RuntimeException('Failed to retrieve updated allocation');
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function removeAllocation(string $serverId, int $allocationId): bool
    {
        $response = $this->makeRequest('DELETE', "servers/$serverId/network/allocations/$allocationId");
        return $response->getStatusCode() === 204;
    }
}
