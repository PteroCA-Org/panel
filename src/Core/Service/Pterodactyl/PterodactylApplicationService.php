<?php

namespace App\Core\Service\Pterodactyl;

use App\Core\Contract\Pterodactyl\Application\PterodactylAdapterInterface;
use App\Core\Contract\Pterodactyl\Client\PterodactylClientAdapterInterface;
use App\Core\Contract\UserInterface;
use App\Core\DTO\Pterodactyl\Credentials;
use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use RuntimeException;

class PterodactylApplicationService
{
    private PterodactylAdapterInterface $applicationCachedAdapter;

    private array $clientCachedAdapters = [];

    public function __construct(
        private readonly SettingService $settingService,
        private readonly PterodactylAdapterInterface $pterodactylAdapter,
        private readonly PterodactylClientAdapterInterface $pterodactylClientAdapter,
    ) {
    }

    public function getApplicationApi(): PterodactylAdapterInterface
    {
        if (isset($this->applicationCachedAdapter)) {
            return $this->applicationCachedAdapter;
        }

        $applicationCredentials = new Credentials(
            $this->getApiUrl(),
            $this->settingService->getSetting(SettingEnum::PTERODACTYL_API_KEY->value),
        );
        $this->pterodactylAdapter->setCredentials($applicationCredentials);
        $this->applicationCachedAdapter = $this->pterodactylAdapter;

        return $this->pterodactylAdapter;
    }

    public function getClientApi(UserInterface $user): PterodactylClientAdapterInterface
    {
        if (isset($this->clientCachedAdapters[$user->getId()])) {
            return $this->clientCachedAdapters[$user->getId()];
        }

        $apiKey = $user->getPterodactylUserApiKey();
        if (empty($apiKey)) {
            // Accounts can be created without a Client API key (e.g. via CLI when the addon is missing)
            throw new RuntimeException('The user account has no Pterodactyl Client API key.');
        }

        $userCredentials = new Credentials(
            $this->getApiUrl(),
            $apiKey,
        );
        $this->pterodactylClientAdapter->setCredentials($userCredentials);
        $this->clientCachedAdapters[$user->getId()] = $this->pterodactylClientAdapter;

        return $this->pterodactylClientAdapter;
    }

    private function getApiUrl(): string
    {
        return $this->settingService
            ->getSetting(SettingEnum::PTERODACTYL_PANEL_URL->value);
    }
}
