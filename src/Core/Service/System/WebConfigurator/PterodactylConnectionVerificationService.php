<?php

namespace App\Core\Service\System\WebConfigurator;

use App\Core\Adapter\Pterodactyl\Application\PterodactylAdapter;
use App\Core\DTO\Action\Result\ConfiguratorVerificationResult;
use App\Core\DTO\Pterodactyl\Credentials;
use App\Core\Enum\SettingEnum;
use App\Core\Service\Pterodactyl\PterocaAddonSecurity;
use App\Core\Service\SettingService;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class PterodactylConnectionVerificationService
{
    public function __construct(
        private TranslatorInterface   $translator,
        private SettingService        $settingService,
        private HttpClientInterface   $httpClient,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function validateExistingConnection(): ConfiguratorVerificationResult
    {
        $pterodactylPanelUrl = $this->settingService->getSetting(SettingEnum::PTERODACTYL_PANEL_URL->value);
        $pterodactylApiKey = $this->settingService->getSetting(SettingEnum::PTERODACTYL_API_KEY->value);

        if (empty($pterodactylPanelUrl) || empty($pterodactylApiKey)) {
            return new ConfiguratorVerificationResult(
                false,
                $this->translator->trans('pteroca.first_configuration.messages.pterodactyl_not_configured'),
            );
        }

        return $this->validateConnection($pterodactylPanelUrl, $pterodactylApiKey);
    }

    public function validateConnection(
        string $pterodactylPanelUrl,
        string $pterodactylPanelApiKey,
    ): ConfiguratorVerificationResult
    {
        try {
            $adapter = new PterodactylAdapter($this->httpClient);
            $credentials = new Credentials($pterodactylPanelUrl, $pterodactylPanelApiKey);
            $adapter->setCredentials($credentials);

            // Test connection by listing servers
            $adapter->servers()->paginate();

            $addonVersion = $this->checkPterocaAddon($adapter);
            if ($addonVersion === null) {
                return new ConfiguratorVerificationResult(
                    false,
                    $this->translator->trans('pteroca.first_configuration.messages.pterodactyl_addon_not_detected'),
                );
            }

            $warning = null;
            if (PterocaAddonSecurity::isOutdated($addonVersion)) {
                $warning = $this->translator->trans('pteroca.admin.addon_security.plain', [
                    '%version%' => $addonVersion,
                    '%minimum%' => PterocaAddonSecurity::MINIMUM_SAFE_VERSION,
                    '%advisory_url%' => PterocaAddonSecurity::ADVISORY_URL,
                    '%command%' => PterocaAddonSecurity::upgradeCommand(),
                ]);
            }

            return new ConfiguratorVerificationResult(
                true,
                $this->translator->trans('pteroca.first_configuration.messages.pterodactyl_api_connection_success'),
                $warning,
            );
        } catch (Exception) {
            return new ConfiguratorVerificationResult(
                false,
                $this->translator->trans('pteroca.first_configuration.messages.pterodactyl_api_error'),
            );
        }
    }

    private function checkPterocaAddon(PterodactylAdapter $adapter): ?string
    {
        try {
            $data = $adapter->pteroca()->getVersion();
            $version = $data['version'] ?? null;
            if (!is_scalar($version)) {
                return null;
            }

            $version = trim((string) $version);

            return $version === '' ? null : $version;
        } catch (Exception) {
            return null;
        }
    }
}
