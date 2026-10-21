<?php

namespace App\Core\Adapter\Pterodactyl\Application;

use App\Core\Adapter\Pterodactyl\AbstractPterodactylAdapter;
use App\Core\DTO\Pterodactyl\Credentials;
use App\Core\Exception\Pterodactyl\PterodactylAccessDeniedException;
use App\Core\Exception\Pterodactyl\PterodactylApplicationApiException;
use App\Core\Exception\Pterodactyl\PterodactylAuthenticationException;
use App\Core\Exception\Pterodactyl\PterodactylConnectionException;
use App\Core\Exception\Pterodactyl\PterodactylNotFoundException;
use App\Core\Exception\Pterodactyl\PterodactylRateLimitException;
use App\Core\Exception\Pterodactyl\PterodactylServerException;
use App\Core\Exception\Pterodactyl\PterodactylValidationException;
use Exception;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class AbstractPterodactylApplicationAdapter extends AbstractPterodactylAdapter
{
    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly Credentials $credentials,
    ) {}

    /**
     * @throws TransportExceptionInterface
     * @throws PterodactylConnectionException
     */
    protected function makeRequest(string $method, string $endpoint, array $additionalOptions = []): ResponseInterface
    {
        $url = sprintf('%s/api/application/%s', $this->credentials->getUrl(), $endpoint);

        $options = array_merge([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->credentials->getApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ], $additionalOptions);

        try {
            return $this->httpClient->request($method, $url, $options);
        } catch (TransportExceptionInterface $e) {
            throw new PterodactylConnectionException(
                sprintf('Failed to connect to Pterodactyl API: %s', $e->getMessage()),
                0,
                $e,
                null,
                $endpoint,
                $method
            );
        } catch (Exception $e) {
            throw new PterodactylConnectionException(
                sprintf('Failed to make %s request to %s: %s', $method, $endpoint, $e->getMessage()),
                0,
                $e,
                null,
                $endpoint,
                $method
            );
        }
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws PterodactylApplicationApiException
     */
    protected function validateServerResponse(ResponseInterface $response, int $expectedStatusCode): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode === $expectedStatusCode) {
            // 204 No Content has an empty body. Decoding it with toArray() throws
            // JsonException even though the request succeeded (for example when
            // deleting an API key). Other success codes still require a JSON body.
            if ($statusCode === 204) {
                return [];
            }

            return $response->toArray();
        }

        $responseBody = $response->getContent(false);
        $endpoint = $response->getInfo('url') ?? 'unknown';
        $method = $response->getInfo('http_method') ?? 'unknown';

        $message = sprintf('Pterodactyl Application API error: %d %s', $statusCode, $responseBody);

        // Create appropriate exception based on status code
        $exception = match (true) {
            $statusCode === 401 => new PterodactylAuthenticationException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 403 => new PterodactylAccessDeniedException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 404 => new PterodactylNotFoundException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 422 => new PterodactylValidationException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 429 => new PterodactylRateLimitException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode >= 500 => new PterodactylServerException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            default => new PterodactylApplicationApiException($message, 0, null, $statusCode, $endpoint, $method, $responseBody)
        };

        throw $exception;
    }
}
