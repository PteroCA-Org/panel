<?php

namespace App\Core\EventSubscriber\Plugin;

use App\Core\Event\Plugin\PluginRegisteredEvent;
use App\Core\Service\Security\PermissionManager;
use App\Core\Service\Security\PermissionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Automatically syncs plugin permissions to database when plugin is registered.
 *
 * When a plugin is registered (discovered and loaded), this subscriber:
 * 1. Retrieves all permissions registered by the plugin from PermissionRegistry
 * 2. Syncs them to the database using PermissionManager
 * 3. Creates/updates Permission entities for each plugin permission
 *
 * This ensures that plugin permissions are available in the database-based
 * permission system and can be assigned to roles.
 */
readonly class PluginPermissionSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PermissionRegistry $permissionRegistry,
        private PermissionManager $permissionManager,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            PluginRegisteredEvent::class => 'onPluginRegistered',
        ];
    }

    public function onPluginRegistered(PluginRegisteredEvent $event): void
    {
        $plugin = $event->getPlugin();
        $pluginName = $plugin->getName();

        try {
            // Get all permissions from registry
            $allPermissions = $this->permissionRegistry->getAllPermissions();

            // Filter permissions that belong to this plugin
            $pluginPermissions = [];
            $pluginPrefix = 'PLUGIN_' . strtoupper(str_replace('-', '_', $pluginName));

            foreach ($allPermissions as $permissionCode => $permissionData) {
                if (str_starts_with($permissionCode, $pluginPrefix)) {
                    $pluginPermissions[$permissionCode] = $permissionData;
                }
            }

            if (empty($pluginPermissions)) {
                $this->logger->debug("No permissions found for plugin", [
                    'plugin' => $pluginName,
                ]);
                return;
            }

            // Sync permissions to database
            $this->permissionManager->syncPluginPermissions($pluginName, $pluginPermissions);

            $this->logger->info("Synced plugin permissions to database", [
                'plugin' => $pluginName,
                'permissionCount' => count($pluginPermissions),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to sync plugin permissions", [
                'plugin' => $pluginName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
