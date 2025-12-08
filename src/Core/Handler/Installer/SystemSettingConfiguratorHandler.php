<?php

namespace App\Core\Handler\Installer;

use App\Core\Enum\SettingEnum;
use App\Core\Repository\SettingRepository;
use App\Core\Service\SettingService;
use App\Core\Service\System\EnvironmentConfigurationService;
use App\Core\Service\System\WebConfigurator\EmailConnectionVerificationService;
use App\Core\Service\System\WebConfigurator\PterodactylConnectionVerificationService;
use App\Core\Service\System\WebConfigurator\UserValidationService;
use Random\RandomException;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemSettingConfiguratorHandler
{
    private bool $isSaved = false;

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly SettingService $settingService,
        private readonly EnvironmentConfigurationService $environmentConfigurationHandler,
        private readonly EmailConnectionVerificationService $emailConnectionVerificationService,
        private readonly PterodactylConnectionVerificationService $pterodactylConnectionVerificationService,
        private readonly UserValidationService $userValidationService,
    ) {}

    /**
     * @throws RandomException
     */
    public function configureSystemSettings(SymfonyStyle $io): void
    {
        $isAlreadyConfigured = $this->settingService->getSetting(SettingEnum::IS_CONFIGURED->value);
        if ($isAlreadyConfigured) {
            $io->warning('System is already configured. Current settings will be overwritten.');
        }

        if ($io->ask('Do you want to configure system settings? (yes/no)', 'yes') === 'yes') {
            $this->generateAppSecretKeyIfNeeded($io);
            $this->askForSiteSettings($io);
            $this->askForTelemetryConsent($io);
            $this->askForPterodactylPanelCredentialsSettings($io);
            $this->askForEmailSettings($io);
            $this->askForPaymentSettings($io);
            $this->askForConfigureUser($io);

            if ($this->isSaved) {
                $this->settingService->saveSetting(SettingEnum::IS_CONFIGURED->value, '1');
            }
        }
    }

    private function askForConfigureUser(SymfonyStyle $io): void
    {
        if ($io->ask('Do you want to create user?', 'yes') === 'yes') {
            $io->section('User configuration');
            $io->text('Please provide details for a new administrator account that will be created in both PteroCA and Pterodactyl. You cannot use an existing account.');

            $email = $io->ask('User e-mail', '');
            $password = $io->ask('User password', '');
            
            $pterodactylUrl = $this->settingService->getSetting(SettingEnum::PTERODACTYL_PANEL_URL->value);
            $pterodactylApiKey = $this->settingService->getSetting(SettingEnum::PTERODACTYL_API_KEY->value);
            
            if ($pterodactylUrl && $pterodactylApiKey) {
                $userValidation = $this->userValidationService->validateUserDoesNotExist($email, $pterodactylUrl, $pterodactylApiKey);
                
                if (!$userValidation->isVerificationSuccessful) {
                    $io->error($userValidation->message);
                    $this->askForConfigureUser($io);
                    return;
                }
            }
            
            $isAdmin = $io->ask('Is user admin? (yes/no)', 'yes') === 'yes';

            $roleName = $isAdmin ? 'admin' : 'user';
            exec(sprintf('php bin/console app:create-new-user %s %s %s', $email, $password, $roleName));
        }
    }

    private function askForPaymentSettings(SymfonyStyle $io): void
    {
        if ($io->ask('Do you want to configure payment settings? (yes/no)', 'yes') === 'yes') {
            $io->section('Payment settings');
            $io->text('Please provide payment settings');

            $settings = [
                SettingEnum::STRIPE_SECRET_KEY->value => [
                    'value' => $io->ask(
                        'Stripe Secret Key (Enter your Stripe account secret key - optional, can be set later)',
                        $this->settingRepository->getSetting(SettingEnum::STRIPE_SECRET_KEY),
                    ),
                ],
                SettingEnum::CURRENCY_NAME->value => [
                    'value' => $io->ask(
                        'Currency (Enter the currency name for payments)',
                        $this->settingRepository->getSetting(SettingEnum::CURRENCY_NAME),
                    ),
                ],
                SettingEnum::INTERNAL_CURRENCY_NAME->value => [
                    'value' => $io->ask(
                        'Internal Currency Name (Enter the internal currency name - e.g. coins)',
                        $this->settingRepository->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME),
                    ),
                ],
            ];
            $this->saveSettings($settings);
        }
    }

    private function askForPterodactylPanelCredentialsSettings(SymfonyStyle $io): void
    {
        if ($io->ask('Do you want to configure pterodactyl panel credentials? (yes/no)', 'yes') === 'yes') {
            $io->section('Pterodactyl panel credentials');
            $io->text('Please provide pterodactyl panel credentials');

            $settings = [
                SettingEnum::PTERODACTYL_PANEL_URL->value => [
                    'value' => $io->ask(
                        'Pterodactyl Panel URL (Enter your Pterodactyl panel URL with protocol - http:// or https://)',
                        $this->settingRepository->getSetting(SettingEnum::PTERODACTYL_PANEL_URL),
                    ),
                ],
                SettingEnum::PTERODACTYL_API_KEY->value => [
                    'value' => $io->ask(
                        'Pterodactyl Panel Application API Key (Enter your Pterodactyl panel Application API key)',
                        $this->settingRepository->getSetting(SettingEnum::PTERODACTYL_API_KEY),
                    ),
                ],
            ];

            $pterodactylConnectionVerification = $this->pterodactylConnectionVerificationService->validateConnection(
                $settings[SettingEnum::PTERODACTYL_PANEL_URL->value]['value'],
                $settings[SettingEnum::PTERODACTYL_API_KEY->value]['value'],
            );

            if (!$pterodactylConnectionVerification->isVerificationSuccessful) {
                $io->error($pterodactylConnectionVerification->message);
                $this->askForPterodactylPanelCredentialsSettings($io);
                return;
            }

            $this->saveSettings($settings);
        }
    }

    private function askForEmailSettings(SymfonyStyle $io): void
    {
        if ($io->ask('Do you want to set email settings? (yes/no)', 'yes') === 'yes') {
            $io->section('Email settings');
            $io->text('Please provide email settings');

            $settings = [
                SettingEnum::EMAIL_SMTP_SERVER->value => [
                    'value' => $io->ask(
                        'SMTP Server (Enter the SMTP server address)',
                        $this->settingRepository->getSetting(SettingEnum::EMAIL_SMTP_SERVER),
                    ),
                ],
                SettingEnum::EMAIL_SMTP_PORT->value => [
                    'value' => $io->ask(
                        'SMTP Port (Enter the SMTP server port. Common ports: 587 (TLS), 465 (SSL))',
                        $this->settingRepository->getSetting(SettingEnum::EMAIL_SMTP_PORT),
                    ),
                ],
                SettingEnum::EMAIL_SMTP_USERNAME->value => [
                    'value' => $io->ask(
                        'SMTP Username (Enter the SMTP username)',
                        $this->settingRepository->getSetting(SettingEnum::EMAIL_SMTP_USERNAME),
                    ),
                ],
                SettingEnum::EMAIL_SMTP_PASSWORD->value => [
                    'value' => $io->ask(
                        'SMTP Password (Enter the SMTP password)',
                        $this->settingRepository->getSetting(SettingEnum::EMAIL_SMTP_PASSWORD),
                    ),
                ],
                SettingEnum::EMAIL_SMTP_FROM->value => [
                    'value' => $io->ask(
                        'SMTP From (Enter the email address for sending emails)',
                        $this->settingRepository->getSetting(SettingEnum::EMAIL_SMTP_FROM),
                    ),
                ],
            ];

            $emailConnectionVerification = $this->emailConnectionVerificationService->validateConnection(
                $settings[SettingEnum::EMAIL_SMTP_USERNAME->value]['value'],
                $settings[SettingEnum::EMAIL_SMTP_PASSWORD->value]['value'],
                $settings[SettingEnum::EMAIL_SMTP_SERVER->value]['value'],
                $settings[SettingEnum::EMAIL_SMTP_PORT->value]['value'],
            );

            if (!$emailConnectionVerification->isVerificationSuccessful) {
                $io->error($emailConnectionVerification->message);
                $this->askForEmailSettings($io);
                return;
            }

            $this->saveSettings($settings);
        }
    }

    private function askForSiteSettings(SymfonyStyle $io): void
    {
        if ($io->ask('Do you want to set site settings? (yes/no)', 'yes') === 'yes') {
            $io->section('Site settings');
            $io->text('Please provide site settings');

            $settings = [
                SettingEnum::SITE_URL->value => [
                    'value' => $io->ask(
                        'Site URL (Enter your website URL with protocol - http:// or https://)',
                        $this->settingRepository->getSetting(SettingEnum::SITE_URL),
                    ),
                ],
                SettingEnum::SITE_TITLE->value => [
                    'value' => $io->ask(
                        'Site Title (Enter your website title)',
                        $this->settingRepository->getSetting(SettingEnum::SITE_TITLE),
                    ),
                ],
                SettingEnum::LOCALE->value => [
                    'value' => $io->ask(
                        'Locale (Select the language for the user interface)',
                        $this->settingRepository->getSetting(SettingEnum::LOCALE),
                    ),
                ],
            ];

            $this->saveSettings($settings);
        }
    }

    private function askForTelemetryConsent(SymfonyStyle $io): void
    {
        $io->section('Telemetry settings');
        $io->text('Help improve PteroCA by sending anonymous usage data.');
        $io->text('We collect anonymous telemetry to improve PteroCA. Only installation events and errors are tracked.');
        $io->text('No personal data, API keys, or URLs are collected.');

        $telemetryConsent = $io->ask(
            'Allow sending anonymous telemetry? (yes/no)',
            'yes'
        );

        $consentValue = ($telemetryConsent === 'yes') ? '1' : '0';

        $settings = [
            SettingEnum::TELEMETRY_CONSENT->value => [
                'value' => $consentValue,
            ],
        ];

        $this->saveSettings($settings);
    }

    /**
     * @throws RandomException
     */
    private function generateAppSecretKeyIfNeeded(SymfonyStyle $io): void
    {
        $appKey = $this->environmentConfigurationHandler->getEnvValue('/^APP_SECRET=(.*)$/m');

        if (empty($appKey)) {
            $appKey = bin2hex(random_bytes(32));
            $pattern = '/^APP_SECRET=(.*)$/m';

            if ($this->environmentConfigurationHandler->getEnvValue($pattern) === '') {
                $pattern = '/^APP_SECRET=/m';
            }

            $this->environmentConfigurationHandler->writeToEnvFile($pattern, "APP_SECRET=$appKey");
            $io->info('App secret key has been generated and saved in .env file');
        }
    }


    private function saveSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $setting = $this->settingRepository->findOneBy(['name' => $key]);
            if (empty($setting)) {
                continue;
            }
            $setting->setValue($value['value']);
            $this->settingRepository->save($setting);
            $this->settingService->saveSettingInCache($key, $value['value']);
            $this->isSaved = true;
        }
    }
}
