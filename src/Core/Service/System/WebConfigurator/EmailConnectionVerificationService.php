<?php

namespace App\Core\Service\System\WebConfigurator;

use App\Core\DTO\Action\Result\ConfiguratorVerificationResult;
use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Mailer\Transport;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class EmailConnectionVerificationService
{
    public function __construct(
        private TranslatorInterface $translator,
        private SettingService $settingService,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function validateExistingConnection(): ConfiguratorVerificationResult
    {
        $emailSmtpServer = $this->settingService->getSetting(SettingEnum::EMAIL_SMTP_SERVER->value);
        $emailSmtpPort = $this->settingService->getSetting(SettingEnum::EMAIL_SMTP_PORT->value);
        $emailSmtpUsername = $this->settingService->getSetting(SettingEnum::EMAIL_SMTP_USERNAME->value);
        $emailSmtpPassword = $this->settingService->getSetting(SettingEnum::EMAIL_SMTP_PASSWORD->value);

        if (empty($emailSmtpServer) || empty($emailSmtpPort) || empty($emailSmtpUsername) || empty($emailSmtpPassword)) {
            return new ConfiguratorVerificationResult(
                false,
                $this->translator->trans('pteroca.first_configuration.messages.smtp_error'),
            );
        }

        return $this->validateConnection(
            $emailSmtpUsername,
            $emailSmtpPassword,
            $emailSmtpServer,
            $emailSmtpPort
        );
    }

    public function validateConnection(
        string $emailSmtpUsername,
        string $emailSmtpPassword,
        string $emailSmtpServer,
        string $emailSmtpPort,
    ): ConfiguratorVerificationResult
    {
        try {
            $dsn = sprintf(
                'smtp://%s:%s@%s:%s',
                urlencode($emailSmtpUsername),
                urlencode($emailSmtpPassword),
                $emailSmtpServer,
                $emailSmtpPort,
            );

            $transport = Transport::fromDsn($dsn);
            $transport->start();

            return new ConfiguratorVerificationResult(
                true,
                $this->translator->trans('pteroca.first_configuration.messages.email_smtp_connection_success'),
            );
        } catch (Exception) {
            return new ConfiguratorVerificationResult(
                false,
                $this->translator->trans('pteroca.first_configuration.messages.smtp_error'),
            );
        }
    }
}