<?php

namespace App\Core\Adapter\Pterodactyl\Client;

use App\Core\Contract\Pterodactyl\Client\PterodactylAccountInterface;
use App\Core\DTO\Pterodactyl\Client\PterodactylAccount as PterodactylAccountDto;
use App\Core\DTO\Pterodactyl\Client\PterodactylApiKey;
use App\Core\DTO\Pterodactyl\Collection;
use Exception;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class PterodactylAccount extends AbstractPterodactylClientAdapter implements PterodactylAccountInterface
{
    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getAccount(): PterodactylAccountDto
    {
        $response = $this->makeRequest('GET', 'account');
        $data = $this->validateClientResponse($response, 200);
        
        return new PterodactylAccountDto($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function updateAccount(array $details): PterodactylAccountDto
    {
        $response = $this->makeRequest('PUT', 'account', ['json' => $details]);
        $statusCode = $response->getStatusCode();

        if (in_array($statusCode, [200, 201])) {
            // API returns data
            $data = $this->validateClientResponse($response, $statusCode);
            return new PterodactylAccountDto($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
        }

        if ($statusCode === 204) {
            // No content returned, fetch updated account
            return $this->getAccount();
        }

        throw new \RuntimeException('Unexpected status code: ' . $statusCode);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function updateEmail(string $email, string $currentPassword): PterodactylAccountDto
    {
        $response = $this->makeRequest('PUT', 'account/email', [
            'json' => [
                'email' => $email,
                'password' => $currentPassword
            ]
        ]);
        $statusCode = $response->getStatusCode();

        if (in_array($statusCode, [200, 201])) {
            // API returns data
            $data = $this->validateClientResponse($response, $statusCode);
            return new PterodactylAccountDto($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
        }

        if ($statusCode === 204) {
            // No content returned, fetch updated account
            return $this->getAccount();
        }

        throw new \RuntimeException('Unexpected status code: ' . $statusCode);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function updatePassword(string $currentPassword, string $newPassword, string $passwordConfirmation): PterodactylAccountDto
    {
        $response = $this->makeRequest('PUT', 'account/password', [
            'json' => [
                'current_password' => $currentPassword,
                'password' => $newPassword,
                'password_confirmation' => $passwordConfirmation
            ]
        ]);
        $statusCode = $response->getStatusCode();

        if (in_array($statusCode, [200, 201])) {
            // API returns data
            $data = $this->validateClientResponse($response, $statusCode);
            return new PterodactylAccountDto($this->getDataFromResponse($data), $this->getMetaFromResponse($data));
        }

        if ($statusCode === 204) {
            // No content returned, fetch updated account
            return $this->getAccount();
        }

        throw new \RuntimeException('Unexpected status code: ' . $statusCode);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function enableTwoFactor(string $code): bool
    {
        $response = $this->makeRequest('POST', 'account/two-factor', [
            'json' => ['code' => $code]
        ]);
        return $response->getStatusCode() === 200;
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function disableTwoFactor(string $password): bool
    {
        $response = $this->makeRequest('POST', 'account/two-factor/disable', [
            'json' => ['password' => $password]
        ]);
        return $response->getStatusCode() === 204;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function getTwoFactorQrCode(): string
    {
        $response = $this->makeRequest('GET', 'account/two-factor/qr');
        
        if ($response->getStatusCode() !== 200) {
            throw new Exception('Failed to retrieve QR code');
        }

        return $response->getContent();
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function getApiKeys(): Collection
    {
        $response = $this->makeRequest('GET', 'account/api-keys');
        $data = $this->validateListResponse($response, 200);

        $items = [];
        foreach ($data['data'] as $keyData) {
            $items[] = new PterodactylApiKey($keyData['attributes']);
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
    public function createApiKey(string $description, array $allowedIps = []): PterodactylApiKey
    {
        $payload = ['description' => $description];
        if (!empty($allowedIps)) {
            $payload['allowed_ips'] = $allowedIps;
        }

        $response = $this->makeRequest('POST', 'account/api-keys', ['json' => $payload]);
        // Pterodactyl returns 200 for created resources; 201 is accepted for compatible panels
        $data = $this->validateClientResponse($response, [200, 201]);
        
        // Dla nowo utworzonego klucza API, dodajemy secret_token z meta
        $attributes = $data['attributes'];
        if (isset($data['meta']['secret_token'])) {
            $attributes['secret_token'] = $data['meta']['secret_token'];
        }

        return new PterodactylApiKey($attributes, $this->getMetaFromResponse($data));
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function deleteApiKey(string $identifier): bool
    {
        $response = $this->makeRequest('DELETE', "account/api-keys/$identifier");
        return $response->getStatusCode() === 204;
    }
}
