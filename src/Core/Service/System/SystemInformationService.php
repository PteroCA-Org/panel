<?php

namespace App\Core\Service\System;

use App\Core\Service\Pterodactyl\PterocaAddonSecurity;
use App\Core\Service\Pterodactyl\PterocaAddonVersionChecker;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

readonly class SystemInformationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PterodactylApplicationService $pterodactylApplicationService,
        private PterocaAddonVersionChecker $pterocaAddonVersionChecker,
    )
    {
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function getSystemInformation(): array
    {
        return [
            'php' => [
                'version' => phpversion(),
                'extensions' => get_loaded_extensions(),
            ],
            'database' => [
                'version' => $this->getDatabaseVersion(),
            ],
            'os' => [
                'name' => php_uname('s'),
                'release' => php_uname('r'),
                'version' => php_uname('v'),
                'machine' => php_uname('m')
            ],
            'webserver' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'pterodactyl' => [
                'status' => $this->isPterodactylApiOnline(),
            ],
            'pteroca_plugin' => $this->getPterocaPluginInformation(),
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function getDatabaseVersion(): string
    {
        try {
            return $this->entityManager
                ->getConnection()
                ->getServerVersion();
        } catch (Exception) {
            return 'N/A';
        }
    }

    private function isPterodactylApiOnline(): bool
    {
        try {
            $this->pterodactylApplicationService
                ->getApplicationApi()
                ->locations()
                ->all();
            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @return array{version: ?string, outdated: bool}
     */
    private function getPterocaPluginInformation(): array
    {
        $version = $this->pterocaAddonVersionChecker->getInstalledVersion();

        return [
            'version' => $version,
            'outdated' => PterocaAddonSecurity::isOutdated($version),
        ];
    }
}
