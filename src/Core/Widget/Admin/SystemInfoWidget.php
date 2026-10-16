<?php

namespace App\Core\Widget\Admin;

use App\Core\Contract\Widget\WidgetInterface;
use App\Core\Enum\WidgetContext;
use App\Core\Enum\WidgetPosition;

/**
 * System information widget for admin overview.
 *
 * Displays system information:
 * - PteroCA version
 * - PteroCA Pterodactyl Addon version
 * - Pterodactyl API status
 * - PHP version
 * - Database version
 * - Webserver
 * - Operating system
 */
class SystemInfoWidget implements WidgetInterface
{
    public function getName(): string
    {
        return 'admin_system_info';
    }

    public function getDisplayName(): string
    {
        return 'System Information';
    }

    public function getSupportedContexts(): array
    {
        return [WidgetContext::ADMIN_OVERVIEW];
    }

    public function getPosition(): WidgetPosition
    {
        return WidgetPosition::RIGHT;
    }

    public function getPriority(): int
    {
        return 100; // High priority - show first in right column
    }

    public function getTemplate(): string
    {
        return 'panel/admin/widgets/system_info.html.twig';
    }

    public function getData(WidgetContext $context, array $contextData): array
    {
        if ($context !== WidgetContext::ADMIN_OVERVIEW) {
            return [];
        }

        $systemInformation = $contextData['systemInformation'] ?? [];

        return [
            'pterocaPluginVersion' => $systemInformation['pteroca_plugin']['version'] ?? null,
            'pterocaPluginOutdated' => (bool) ($systemInformation['pteroca_plugin']['outdated'] ?? false),
            'pterodactylStatus' => $systemInformation['pterodactyl']['status'] ?? false,
            'phpVersion' => $systemInformation['php']['version'] ?? 'unknown',
            'databaseVersion' => $systemInformation['database']['version'] ?? 'unknown',
            'webserver' => $systemInformation['webserver'] ?? 'unknown',
            'os' => $systemInformation['os'] ?? [],
        ];
    }

    public function isVisible(WidgetContext $context, array $contextData): bool
    {
        return $context === WidgetContext::ADMIN_OVERVIEW
            && isset($contextData['systemInformation']);
    }

    public function getColumnSize(): int
    {
        return 12; // Full width within RIGHT position
    }
}
