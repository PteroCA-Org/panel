<?php

namespace App\Core\Service\System\WebConfigurator;

use App\Core\DTO\Action\Result\ConfiguratorVerificationResult;
use App\Core\Entity\User;
use App\Core\Enum\SettingEnum;
use App\Core\Repository\UserRepository;
use App\Core\Service\Authorization\RegistrationService;
use App\Core\Service\Security\RoleManager;
use App\Core\Service\SettingService;
use App\Core\Service\Telemetry\TelemetryService;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

class FinishConfigurationService
{
    private const REQUIRED_SETTINGS_MAP = [
        SettingEnum::SITE_URL->value => 'site_url',
        SettingEnum::SITE_TITLE->value => 'site_title',
        SettingEnum::LOCALE->value => 'site_locale',
        SettingEnum::PTERODACTYL_PANEL_URL->value => 'pterodactyl_panel_url',
        SettingEnum::PTERODACTYL_API_KEY->value => 'pterodactyl_panel_api_key',
        SettingEnum::CURRENCY_NAME->value => 'currency',
        SettingEnum::INTERNAL_CURRENCY_NAME->value => 'internal_currency_name',
    ];

    private const OPTIONAL_SETTINGS_MAP = [
        SettingEnum::EMAIL_SMTP_SERVER->value => 'email_smtp_server',
        SettingEnum::EMAIL_SMTP_PORT->value => 'email_smtp_port',
        SettingEnum::EMAIL_SMTP_USERNAME->value => 'email_smtp_username',
        SettingEnum::EMAIL_SMTP_PASSWORD->value => 'email_smtp_password',
        SettingEnum::EMAIL_SMTP_FROM->value => 'email_smtp_from',
        SettingEnum::STRIPE_SECRET_KEY->value => 'stripe_secret_key',
        SettingEnum::TELEMETRY_CONSENT->value => 'telemetry_consent',
    ];

    public function __construct(
        private readonly SettingService $settingService,
        private readonly EmailConnectionVerificationService $emailConnectionVerificationService,
        private readonly PterodactylConnectionVerificationService $pterodactylConnectionVerificationService,
        private readonly RegistrationService $registrationService,
        private readonly TranslatorInterface $translator,
        private readonly RoleManager $roleManager,
        private readonly UserRepository $userRepository,
        private readonly TelemetryService $telemetryService,
    ) {}

    public function getRequiredSettingsMap(): array
    {
        return self::REQUIRED_SETTINGS_MAP;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function finishConfiguration(array $data): ConfiguratorVerificationResult
    {
        try {
            $isEmailConnectionValidated = $this->emailConnectionVerificationService->validateConnection(
                $data['email_smtp_username'],
                $data['email_smtp_password'],
                $data['email_smtp_server'],
                $data['email_smtp_port'],
            );
            if (!$isEmailConnectionValidated->isVerificationSuccessful) {
                $data = $this->clearEmailSettings($data);
            }

            if (!empty($data['useExistingPterodactylSettings']) && $data['useExistingPterodactylSettings'] === 'true') {
                $data['pterodactyl_panel_url'] = $this->settingService->getSetting(SettingEnum::PTERODACTYL_PANEL_URL->value);
                $data['pterodactyl_panel_api_key'] = $this->settingService->getSetting(SettingEnum::PTERODACTYL_API_KEY->value);
            }

            $isPterodactylConnectionValid = $this->validatePterodactylConnection($data['pterodactyl_panel_url'], $data['pterodactyl_panel_api_key']);
            if (!$isPterodactylConnectionValid) {
                $this->telemetryService->sendInstallErrorEvent('pterodactyl_connection_failed');
                return new ConfiguratorVerificationResult(
                    false,
                    $this->translator->trans('pteroca.first_configuration.messages.pterodactyl_api_error'),
                );
            }

            $this->saveConfigurationSettings($data);

            if (!$this->createAdminAccount($data)) {
                $this->telemetryService->sendInstallErrorEvent('admin_account_creation_failed');
                return new ConfiguratorVerificationResult(
                    false,
                    $this->translator->trans('pteroca.first_configuration.messages.validation_error'),
                );
            }

            $this->disableConfigurator();

            return new ConfiguratorVerificationResult(true);
        } catch (\Throwable $e) {
            $this->telemetryService->sendInstallErrorEvent('configuration_exception');
            throw $e;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function saveConfigurationSettings(array $data): void
    {
        $settingsMap = array_merge(self::REQUIRED_SETTINGS_MAP, self::OPTIONAL_SETTINGS_MAP);

        foreach ($settingsMap as $setting => $key) {
            $preparedValue = $data[$key] ?? '';
            $preparedValue = is_string($preparedValue) ? trim($preparedValue) : $preparedValue;
            $this->settingService->saveSetting($setting, $preparedValue);
        }
    }

    private function createAdminAccount(array $data): bool
    {
        if (empty($data['admin_email']) || empty($data['admin_password'])) {
            return false;
        }

        $user = new User();
        $user->setName('Admin');
        $user->setSurname('Admin');
        $user->setEmail($data['admin_email']);

        // Register user without roles (empty array) - we'll assign via RoleManager below
        $registerResult = $this->registrationService->registerUser(
            $user,
            $data['admin_password'],
            [],
            true,
        );

        if (!$registerResult->success) {
            return false;
        }

        // Assign admin role using RoleManager
        $adminRole = $this->roleManager->getRoleByName('admin');
        if ($adminRole && $registerResult->user) {
            $registerResult->user->addUserRole($adminRole);
            $this->userRepository->save($registerResult->user);
        }

        return true;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function disableConfigurator(): void
    {
        $this->settingService->saveSetting(SettingEnum::IS_CONFIGURED->value, '1');
    }

    private function clearEmailSettings(array $data): array
    {
        return array_diff_key($data, array_flip([
            'email_smtp_server',
            'email_smtp_port',
            'email_smtp_username',
            'email_smtp_password',
            'email_smtp_from',
        ]));
    }

    private function validatePterodactylConnection(string $url, string $apiKey): bool
    {
        $result = $this->pterodactylConnectionVerificationService->validateConnection($url, $apiKey);

        return $result->isVerificationSuccessful;
    }
}
