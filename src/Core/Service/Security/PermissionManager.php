<?php

namespace App\Core\Service\Security;

use App\Core\Entity\Permission;
use App\Core\Repository\PermissionRepository;
use App\Core\Repository\PluginRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for managing permissions.
 * Handles CRUD operations for permissions and plugin permission synchronization.
 */
readonly class PermissionManager
{
    public function __construct(
        private PermissionRepository $permissionRepository,
        private PluginRepository $pluginRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Create a new permission.
     *
     * @throws RuntimeException if permission code already exists
     */
    public function createPermission(
        string $code,
        string $name,
        string $section,
        ?string $description = null,
        bool $isSystem = false,
        ?string $pluginName = null
    ): Permission {
        // Check if permission already exists
        $existing = $this->permissionRepository->findByCode($code);
        if ($existing !== null) {
            throw new RuntimeException("Permission with code '{$code}' already exists");
        }

        $permission = new Permission();
        $permission->setCode($code);
        $permission->setName($name);
        $permission->setSection($section);
        $permission->setDescription($description);
        $permission->setIsSystem($isSystem);
        $permission->setPluginName($pluginName);

        $this->permissionRepository->save($permission);

        $this->logger->info("Permission created", [
            'code' => $code,
            'section' => $section,
            'plugin' => $pluginName,
        ]);

        return $permission;
    }

    /**
     * Update an existing permission.
     * System permissions cannot be updated (code, section, isSystem fields are protected).
     *
     * @throws RuntimeException if permission is system permission
     */
    public function updatePermission(
        Permission $permission,
        string $name,
        ?string $description = null
    ): Permission {
        if ($permission->isSystem()) {
            throw new RuntimeException("Cannot update system permission '{$permission->getCode()}'");
        }

        $permission->setName($name);
        $permission->setDescription($description);

        $this->permissionRepository->save($permission);

        $this->logger->info("Permission updated", [
            'code' => $permission->getCode(),
        ]);

        return $permission;
    }

    /**
     * Delete a permission.
     * System permissions cannot be deleted.
     *
     * @throws RuntimeException if permission is system permission
     */
    public function deletePermission(Permission $permission): void
    {
        if ($permission->isSystem()) {
            throw new RuntimeException("Cannot delete system permission '{$permission->getCode()}'");
        }

        $code = $permission->getCode();
        $this->permissionRepository->remove($permission);

        $this->logger->info("Permission deleted", [
            'code' => $code,
        ]);
    }

    /**
     * Get all permissions for a specific plugin.
     */
    public function getPermissionsByPlugin(string $pluginName): array
    {
        return $this->permissionRepository->findByPlugin($pluginName);
    }

    /**
     * Get all permissions for a specific section.
     */
    public function getPermissionsBySection(string $section): array
    {
        return $this->permissionRepository->findBySection($section);
    }

    /**
     * Get all active permissions (core + enabled plugins only).
     */
    public function getActivePermissions(): array
    {
        $enabledPlugins = $this->pluginRepository->findEnabledPlugins();
        $enabledPluginNames = array_map(fn($plugin) => $plugin->getName(), $enabledPlugins);

        return $this->permissionRepository->findActivePermissions($enabledPluginNames);
    }

    /**
     * Synchronize plugin permissions to database.
     * Creates/updates permissions from PermissionRegistry for a specific plugin.
     *
     * @param string $pluginName Plugin name
     * @param array $permissions Array of permission data from PermissionRegistry
     *                          Format: ['name' => string, 'description' => string, 'requiredRoles' => array]
     */
    public function syncPluginPermissions(string $pluginName, array $permissions): void
    {
        $created = 0;
        $updated = 0;

        foreach ($permissions as $permissionCode => $permissionData) {
            // Skip if not a plugin permission (must start with PLUGIN_)
            if (!str_starts_with($permissionCode, 'PLUGIN_')) {
                continue;
            }

            // Extract section from permission code (e.g., PLUGIN_MY_PLUGIN_ADMIN -> my_plugin)
            $section = $this->extractSectionFromPluginPermission($permissionCode, $pluginName);

            $existing = $this->permissionRepository->findByCode($permissionCode);

            if ($existing === null) {
                // Create new permission
                $permission = new Permission();
                $permission->setCode($permissionCode);
                $permission->setName($permissionData['name'] ?? $permissionCode);
                $permission->setDescription($permissionData['description']);
                $permission->setSection($section);
                $permission->setIsSystem(false);
                $permission->setPluginName($pluginName);

                $this->permissionRepository->save($permission, false);
                $created++;
            } else {
                // Update existing permission (only if it belongs to this plugin)
                if ($existing->getPluginName() === $pluginName) {
                    $existing->setName($permissionData['name'] ?? $permissionCode);
                    $existing->setDescription($permissionData['description']);
                    $this->permissionRepository->save($existing, false);
                    $updated++;
                }
            }
        }

        if ($created > 0 || $updated > 0) {
            $this->permissionRepository->save(new Permission(), true); // Flush all changes

            $this->logger->info("Synced plugin permissions", [
                'plugin' => $pluginName,
                'created' => $created,
                'updated' => $updated,
            ]);
        }
    }

    /**
     * Delete all permissions for a specific plugin.
     * Called when plugin is uninstalled.
     */
    public function deletePluginPermissions(string $pluginName): int
    {
        $count = $this->permissionRepository->deleteByPlugin($pluginName);

        $this->logger->info("Deleted plugin permissions", [
            'plugin' => $pluginName,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Extract section name from plugin permission code.
     * E.g., PLUGIN_MY_PLUGIN_ADMIN -> my_plugin
     */
    private function extractSectionFromPluginPermission(string $permissionCode, string $pluginName): string
    {
        // Convert plugin name to uppercase and replace dashes with underscores
        $pluginPrefix = 'PLUGIN_' . strtoupper(str_replace('-', '_', $pluginName));

        // If permission starts with plugin prefix, use plugin name as section
        if (str_starts_with($permissionCode, $pluginPrefix)) {
            return 'plugin_' . $pluginName;
        }

        // Fallback: use 'plugins' as section
        return 'plugins';
    }

    /**
     * Get all sections with their permission counts.
     */
    public function getSectionsWithCount(): array
    {
        return $this->permissionRepository->getSectionsWithCount();
    }

    /**
     * Check if permission exists by code.
     */
    public function hasPermission(string $code): bool
    {
        return $this->permissionRepository->findByCode($code) !== null;
    }

    /**
     * Get permission by code.
     */
    public function getPermission(string $code): ?Permission
    {
        return $this->permissionRepository->findByCode($code);
    }
}
