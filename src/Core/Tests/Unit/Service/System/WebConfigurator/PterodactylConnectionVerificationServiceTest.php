<?php

namespace App\Core\Tests\Unit\Service\System\WebConfigurator;

use App\Core\Enum\SettingEnum;
use App\Core\Service\Pterodactyl\PterocaAddonSecurity;
use App\Core\Service\SettingService;
use App\Core\Service\System\WebConfigurator\PterodactylConnectionVerificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PterodactylConnectionVerificationServiceTest extends TestCase
{
    public function testOutdatedAddonIsAWarningAndDoesNotBlockConnection(): void
    {
        $result = $this->service($this->httpClient('0.1.3'))
            ->validateConnection('https://panel.example.com', 'ptla_test');

        $this->assertTrue($result->isVerificationSuccessful);
        $this->assertSame(
            'pteroca.first_configuration.messages.pterodactyl_api_connection_success',
            $result->message
        );
        $this->assertNotNull($result->warning);
        $this->assertStringContainsString('0.1.3', $result->warning);
        $this->assertStringContainsString(PterocaAddonSecurity::MINIMUM_SAFE_VERSION, $result->warning);
        $this->assertStringContainsString(PterocaAddonSecurity::ADVISORY_URL, $result->warning);
        $this->assertStringContainsString(PterocaAddonSecurity::upgradeCommand(), $result->warning);
    }

    public function testSafeAddonHasNoWarning(): void
    {
        $result = $this->service($this->httpClient('0.1.4'))
            ->validateConnection('https://panel.example.com', 'ptla_test');

        $this->assertTrue($result->isVerificationSuccessful);
        $this->assertNull($result->warning);
    }

    public function testNewerAddonHasNoWarning(): void
    {
        $result = $this->service($this->httpClient('0.1.10'))
            ->validateConnection('https://panel.example.com', 'ptla_test');

        $this->assertTrue($result->isVerificationSuccessful);
        $this->assertNull($result->warning);
    }

    public function testUnknownVersionStringDoesNotWarn(): void
    {
        $result = $this->service($this->httpClient('dev-main'))
            ->validateConnection('https://panel.example.com', 'ptla_test');

        $this->assertTrue($result->isVerificationSuccessful);
        $this->assertNull($result->warning);
    }

    public function testMissingVersionStillBlocksBecauseAddonWasNotDetected(): void
    {
        $result = $this->service($this->httpClient(null))
            ->validateConnection('https://panel.example.com', 'ptla_test');

        $this->assertFalse($result->isVerificationSuccessful);
        $this->assertSame(
            'pteroca.first_configuration.messages.pterodactyl_addon_not_detected',
            $result->message
        );
        $this->assertNull($result->warning);
    }

    public function testVersionEndpointFailureStillBlocksBecauseAddonWasNotDetected(): void
    {
        $servers = $this->jsonResponse(200, ['data' => [], 'meta' => []]);
        $version = $this->createMock(ResponseInterface::class);
        $version->method('getStatusCode')->willReturn(404);
        $version->method('getContent')->willReturn('not found');
        $version->method('getInfo')->willReturn('https://panel.example.com/api/application/pteroca/version');

        $result = $this->service($this->httpClientWithResponses($servers, $version))
            ->validateConnection('https://panel.example.com', 'ptla_test');

        $this->assertFalse($result->isVerificationSuccessful);
        $this->assertNull($result->warning);
    }

    public function testExistingConnectionWithoutCredentialsFailsBeforeCallingTheApi(): void
    {
        $settings = $this->createMock(SettingService::class);
        $settings->method('getSetting')->willReturnMap([
            [SettingEnum::PTERODACTYL_PANEL_URL->value, ''],
            [SettingEnum::PTERODACTYL_API_KEY->value, ''],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $result = $this->service($httpClient, $settings)->validateExistingConnection();

        $this->assertFalse($result->isVerificationSuccessful);
        $this->assertSame(
            'pteroca.first_configuration.messages.pterodactyl_not_configured',
            $result->message
        );
    }

    private function service(
        HttpClientInterface $httpClient,
        ?SettingService $settings = null,
    ): PterodactylConnectionVerificationService {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []): string {
                if ($parameters === []) {
                    return $id;
                }

                return $id . '|' . json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        );

        return new PterodactylConnectionVerificationService(
            $translator,
            $settings ?? $this->createMock(SettingService::class),
            $httpClient,
        );
    }

    private function httpClient(?string $version): HttpClientInterface
    {
        $payload = $version === null ? [] : ['version' => $version];

        return $this->httpClientWithResponses(
            $this->jsonResponse(200, ['data' => [], 'meta' => []]),
            $this->jsonResponse(200, $payload),
        );
    }

    private function httpClientWithResponses(
        ResponseInterface $serversResponse,
        ResponseInterface $versionResponse,
    ): HttpClientInterface {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            static function (string $method, string $url) use ($serversResponse, $versionResponse): ResponseInterface {
                return str_contains($url, 'pteroca/version') ? $versionResponse : $serversResponse;
            }
        );

        return $httpClient;
    }

    private function jsonResponse(int $statusCode, array $payload): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->willReturn($payload);

        return $response;
    }
}
