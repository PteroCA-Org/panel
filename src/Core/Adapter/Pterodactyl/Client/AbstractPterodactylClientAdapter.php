<?php

namespace App\Core\Adapter\Pterodactyl\Client;

use App\Core\Adapter\Pterodactyl\AbstractPterodactylAdapter;
use App\Core\DTO\Pterodactyl\Credentials;
use App\Core\Exception\Pterodactyl\PterodactylAccessDeniedException;
use App\Core\Exception\Pterodactyl\PterodactylAuthenticationException;
use App\Core\Exception\Pterodactyl\PterodactylClientApiException;
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

abstract class AbstractPterodactylClientAdapter extends AbstractPterodactylAdapter
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
        $url = sprintf('%s/api/client/%s', $this->credentials->getUrl(), $endpoint);

        if (!empty($additionalOptions['query'])) {
            $url .= '?' . http_build_query($additionalOptions['query']);
        }

        if (empty($additionalOptions['headers']['Authorization'])) {
            $additionalOptions['headers']['Authorization'] = 'Bearer ' . $this->credentials->getApiKey();
        }

        if (empty($additionalOptions['headers']['Accept'])) {
            $additionalOptions['headers']['Accept'] = 'Application/vnd.pterodactyl.v1+json';
        }

        if (empty($additionalOptions['headers']['Content-Type'])) {
            $additionalOptions['headers']['Content-Type'] = 'application/json';
        }

        try {
            return $this->httpClient->request($method, $url, $additionalOptions);
        } catch (TransportExceptionInterface $e) {
            throw new PterodactylConnectionException(
                sprintf('Failed to connect to Pterodactyl Client API: %s', $e->getMessage()),
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
     * @throws PterodactylClientApiException
     */
    protected function validateClientResponse(ResponseInterface $response, int $expectedStatusCode): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode !== $expectedStatusCode) {
            $this->throwClientApiException($response, $statusCode);
        }

        $data = $response->toArray();

        if (!isset($data['attributes'])) {
            throw new PterodactylClientApiException(
                'Invalid response format from Pterodactyl Client API - missing attributes',
                0,
                null,
                $statusCode,
                $response->getInfo('url') ?? 'unknown',
                $response->getInfo('http_method') ?? 'unknown'
            );
        }

        return $data;
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws PterodactylClientApiException
     */
    protected function validateListResponse(ResponseInterface $response, int $expectedStatusCode): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode !== $expectedStatusCode) {
            $this->throwClientApiException($response, $statusCode);
        }

        $data = $response->toArray();

        if (!isset($data['data'])) {
            throw new PterodactylClientApiException(
                'Invalid list response format from Pterodactyl Client API - missing data',
                0,
                null,
                $statusCode,
                $response->getInfo('url') ?? 'unknown',
                $response->getInfo('http_method') ?? 'unknown'
            );
        }

        return $data;
    }

    /**
     * @throws PterodactylClientApiException
     */
    protected function throwClientApiException(ResponseInterface $response, int $statusCode): never
    {
        $responseBody = $response->getContent(false);
        $endpoint = $response->getInfo('url') ?? 'unknown';
        $method = $response->getInfo('http_method') ?? 'unknown';

        $message = sprintf('Pterodactyl Client API error: %d %s %s', $statusCode, $endpoint, $responseBody);

        // Create appropriate exception based on status code
        $exception = match (true) {
            $statusCode === 401 => new PterodactylAuthenticationException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 403 => new PterodactylAccessDeniedException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 404 => new PterodactylNotFoundException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 422 => new PterodactylValidationException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode === 429 => new PterodactylRateLimitException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            $statusCode >= 500 => new PterodactylServerException($message, 0, null, $statusCode, $endpoint, $method, $responseBody),
            default => new PterodactylClientApiException($message, 0, null, $statusCode, $endpoint, $method, $responseBody)
        };

        throw $exception;
    }
}
