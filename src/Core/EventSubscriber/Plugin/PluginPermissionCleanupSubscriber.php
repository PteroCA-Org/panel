<?php

namespace App\Core\EventSubscriber\Plugin;

use App\Core\Event\Plugin\PluginDisabledEvent;
use App\Core\Service\Security\PermissionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles plugin permission cleanup when plugin is disabled.
 *
 * When a plugin is disabled, this subscriber:
 * 1. Logs information about the plugin's permissions
 * 2. Permissions remain in the database but are filtered out from active permissions
 * 3. Role assignments are preserved for when/if the plugin is re-enabled
 *
 * Note: Permissions are NOT automatically deleted when a plugin is disabled.
 * This preserves role assignments and permission history. Administrators can
 * manually delete plugin permissions through the admin panel if needed.
 *
 * To completely remove plugin permissions, use PermissionManager::deletePluginPermissions()
 * manually or through a dedicated cleanup command.
 */
readonly class PluginPermissionCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PermissionManager $permissionManager,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            PluginDisabledEvent::class => 'onPluginDisabled',
        ];
    }

    public function onPluginDisabled(PluginDisabledEvent $event): void
    {
        $plugin = $event->getPlugin();
        $pluginName = $plugin->getName();

        try {
            // Get plugin permissions (they still exist in database)
            $permissions = $this->permissionManager->getPermissionsByPlugin($pluginName);

            if (empty($permissions)) {
                $this->logger->debug("No permissions found for disabled plugin", [
                    'plugin' => $pluginName,
                ]);
                return;
            }

            $this->logger->info("Plugin disabled - permissions remain in database but are inactive", [
                'plugin' => $pluginName,
                'permissionCount' => count($permissions),
                'note' => 'Permissions will be automatically filtered out from active permissions. Role assignments are preserved.',
            ]);

            // Note: We don't delete permissions here to preserve role assignments
            // If you want to delete permissions, call:
            // $this->permissionManager->deletePluginPermissions($pluginName);
        } catch (\Throwable $e) {
            $this->logger->error("Error while processing disabled plugin permissions", [
                'plugin' => $pluginName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
