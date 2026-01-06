<?php

namespace App\Core\Service\Telemetry;

use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use App\Core\Service\System\SystemVersionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class TelemetryService
{
    private const TELEMETRY_ENDPOINT = 'https://telemetry.pteroca.com/api/v1/ingest';
    private const SHARED_SECRET = '6a285b05868eb508dbcc67f012bcb91d3957251689c3fc987256e0c5c2d5bdff';
    private const SCHEMA_VERSION = 1;
    private const SOURCE = 'panel';

    public function __construct(
        private SettingService $settingService,
        private SystemVersionService $systemVersionService,
        private HttpClientInterface $httpClient,
    ) {}

    public function sendInstallCompleteEvent(): void
    {
        $this->sendEvent('install.complete', []);
    }

    public function sendInstallErrorEvent(string $errorType): void
    {
        $this->sendEvent('install.error', [
            'error_type' => $errorType,
        ]);
    }

    public function send500ErrorEvent(\Throwable $exception, ?Request $request = null): void
    {
        $payload = [
            'error_class' => get_class($exception),
            'error_code' => $exception->getCode(),
            'error_message' => $this->sanitizeExceptionMessage($exception->getMessage()),
        ];

        if ($request !== null) {
            $routeName = $request->attributes->get('_route');
            if ($routeName !== null && is_string($routeName)) {
                $payload['route'] = $routeName;
            }
        }

        $this->sendEvent('error.500', $payload);
    }

    private function sanitizeExceptionMessage(string $message): string
    {
        $message = preg_replace(
            '/https?:\/\/[^\s]+/i',
            '[URL_REDACTED]',
            $message
        );

        if (mb_strlen($message) > 500) {
            $message = mb_substr($message, 0, 500) . '... [truncated]';
        }

        return $message;
    }

    private function sendEvent(string $eventType, array $payload): void
    {
        if (!$this->hasConsent()) {
            return;
        }

        try {
            $telemetryPayload = $this->buildTelemetryPayload($eventType, $payload);
            $signature = $this->generateSignature($telemetryPayload);

            $this->httpClient->request('POST', self::TELEMETRY_ENDPOINT, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-PCA-Signature' => $signature,
                ],
                'body' => $telemetryPayload,
                'timeout' => 0.1,
            ]);
        } catch (\Throwable) {
            // Silent failure - ignore telemetry errors
        }
    }

    private function buildTelemetryPayload(string $eventType, array $eventPayload): string
    {
        $data = [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => self::SOURCE,
            'client_version' => $this->systemVersionService->getCurrentVersion(),
            'events' => [
                [
                    'type' => $eventType,
                    'ts' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'payload' => $eventPayload,
                    'idempotency_key' => $this->generateUuid(),
                ],
            ],
        ];

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function generateSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::SHARED_SECRET);
    }

    private function hasConsent(): bool
    {
        try {
            $consent = $this->settingService->getSetting(SettingEnum::TELEMETRY_CONSENT->value);
            return $consent === '1' || $consent === 'true';
        } catch (\Throwable) {
            return false;
        }
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
