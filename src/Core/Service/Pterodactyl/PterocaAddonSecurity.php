<?php

namespace App\Core\Service\Pterodactyl;

/**
 * Minimum safe PteroCA Pterodactyl addon release.
 *
 * Bump MINIMUM_SAFE_VERSION when a newer addon release is required.
 * ADVISORY_URL points at the advisory for the current floor.
 */
final class PterocaAddonSecurity
{
    public const MINIMUM_SAFE_VERSION = '0.1.4';

    public const ADVISORY_URL = 'https://github.com/PteroCA-Org/pterodactyl-addon/security/advisories/GHSA-77vh-c4jh-rv29';

    public static function upgradeCommand(): string
    {
        return 'composer require pteroca-com/pterodactyl-addon:^' . self::MINIMUM_SAFE_VERSION;
    }

    /**
     * True only when the version is a comparable release below the safe floor.
     * Missing or non-semver values are unknown and are not treated as outdated.
     */
    public static function isOutdated(?string $version): bool
    {
        $normalized = self::normalize($version);
        if ($normalized === null) {
            return false;
        }

        return version_compare($normalized, self::MINIMUM_SAFE_VERSION, '<');
    }

    public static function normalize(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $version = trim($version);
        if (str_starts_with(strtolower($version), 'v')) {
            $version = substr($version, 1);
        }

        if ($version === '' || !preg_match('/^\d+(?:\.\d+)*/', $version)) {
            return null;
        }

        return $version;
    }
}
