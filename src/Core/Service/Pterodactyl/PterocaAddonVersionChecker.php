<?php

namespace App\Core\Service\Pterodactyl;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * Reads the installed PteroCA addon version from the Pterodactyl application API.
 * The result is cached so admin pages do not call GET /api/application/pteroca/version
 * on every request.
 */
readonly class PterocaAddonVersionChecker
{
    private const CACHE_KEY = 'pteroca.addon.installed_version';

    private const CACHE_TTL = 300;

    // An unknown version raises no warning, so retrying rarely avoids blocking admin pages when Pterodactyl is down
    private const UNKNOWN_VERSION_TTL = 600;

    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private CacheInterface $cache,
    ) {}

    public function getInstalledVersion(): ?string
    {
        try {
            $version = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?string {
                $version = $this->fetchInstalledVersion();
                $item->expiresAfter($version === null ? self::UNKNOWN_VERSION_TTL : self::CACHE_TTL);

                return $version;
            });
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function clearCache(): void
    {
        try {
            $this->cache->delete(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    private function fetchInstalledVersion(): ?string
    {
        try {
            $data = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->pteroca()
                ->getVersion();
        } catch (Throwable) {
            return null;
        }

        $version = $data['version'] ?? null;
        if (!is_scalar($version)) {
            return null;
        }

        $version = trim((string) $version);

        return $version === '' ? null : $version;
    }
}
