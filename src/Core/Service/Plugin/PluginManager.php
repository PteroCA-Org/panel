<?php

namespace App\Core\Service\Plugin;

use App\Core\Entity\Plugin;
use Exception;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use App\Core\Enum\PluginStateEnum;
use App\Core\DTO\PluginManifestDTO;
use App\Core\Repository\PluginRepository;
use App\Core\Event\Plugin\PluginEnabledEvent;
use App\Core\Event\Plugin\PluginFaultedEvent;
use App\Core\Event\Plugin\PluginUpdatedEvent;
use App\Core\Event\Plugin\PluginDisabledEvent;
use App\Core\Event\Plugin\PluginDiscoveredEvent;
use App\Core\Event\Plugin\PluginRegisteredEvent;
use RuntimeException;
use Symfony\Component\HttpKernel\KernelInterface;
use App\Core\Exception\Plugin\InvalidStateTransitionException;
use App\Core\Exception\Plugin\PluginDependencyException;
use App\Core\Event\Plugin\PluginEnablementFailedEvent;
use App\Core\Event\Plugin\PluginDisablementFailedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

readonly class PluginManager
{
    public function __construct(
        private PluginRepository         $pluginRepository,
        private PluginScanner            $pluginScanner,
        private ManifestValidator        $manifestValidator,
        private PluginStateMachine       $stateMachine,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface          $logger,
        private PluginLoader             $pluginLoader,
        private PluginMigrationService   $migrationService,
        private KernelInterface          $kernel,
        private PluginDependencyResolver $dependencyResolver,
        private PluginAssetManager       $assetManager,
        private PluginSettingService     $settingService,
        private PluginSecurityValidator  $securityValidator,
        private ComposerDependencyManager $composerManager,
        private EnabledPluginsCacheManager $cacheManager,
    ) {}

    /**
     * Discover and register all plugins from filesystem.
     *
     * This method is part of the public API and may be used by CLI commands,
     * scheduled tasks, or plugin management interfaces.
     *
     * @api
     * @return array{discovered: int, registered: int, failed: int, errors: array}
     */
    public function discoverAndRegisterPlugins(): array
    {
        $discovered = 0;
        $registered = 0;
        $failed = 0;
        $errors = [];

        // Scan plugins directory
        $scannedPlugins = $this->pluginScanner->scan();

        foreach ($scannedPlugins as $pluginName => $data) {
            ++$discovered;

            try {
                // Check if plugin already exists
                $existingPlugin = $this->pluginRepository->findByName($pluginName);

                if ($existingPlugin !== null) {
                    // Check for version update
                    if ($existingPlugin->getVersion() !== $data['manifest']->version) {
                        $this->handlePluginUpdate($existingPlugin, $data['manifest']);
                    }
                    continue;
                }

                // Validate manifest
                if (count($data['errors']) > 0) {
                    $errors[$pluginName] = $data['errors'];
                    ++$failed;
                    $this->logger->warning("Plugin $pluginName has validation errors", $data['errors']);
                    continue;
                }

                // Register new plugin
                $this->registerPlugin($data['path'], $data['manifest']);
                ++$registered;

                $this->logger->info("Registered new plugin: $pluginName");
            } catch (Exception $e) {
                ++$failed;
                $errors[$pluginName] = [$e->getMessage()];
                $this->logger->error("Failed to register plugin $pluginName: {$e->getMessage()}");
            }
        }

        return [
            'discovered' => $discovered,
            'registered' => $registered,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function registerPlugin(string $pluginPath, PluginManifestDTO $manifest): Plugin
    {
        // Check if plugin with this name already exists in DB
        $existingPlugin = $this->pluginRepository->findByName($manifest->name);

        if ($existingPlugin !== null) {
            // Plugin already registered - update version if needed
            if ($existingPlugin->getVersion() !== $manifest->version) {
                $this->handlePluginUpdate($existingPlugin, $manifest);
            }
            return $existingPlugin;
        }

        // Clean up any orphaned settings from previous installations
        $orphanedSettingsCount = $this->settingService->deleteAll($manifest->name);
        if ($orphanedSettingsCount > 0) {
            $this->logger->info("Cleaned up orphaned settings during plugin re-registration", [
                'plugin' => $manifest->name,
                'count' => $orphanedSettingsCount,
            ]);
        }

        // Create plugin entity
        $plugin = $this->createPluginEntityFromManifest($pluginPath, $manifest);

        // Validate PteroCA compatibility
        if (!$this->manifestValidator->isCompatibleWithPteroCA($manifest)) {
            $errorMessage = $this->manifestValidator->getCompatibilityError($manifest);
            $this->stateMachine->transitionToFaulted($plugin, $errorMessage);

            // Dispatch faulted event
            $this->eventDispatcher->dispatch(new PluginFaultedEvent($plugin, $errorMessage));
        } else {
            // Transition to REGISTERED state
            $this->stateMachine->transitionToRegistered($plugin);

            // Dispatch discovered and registered events
            $this->eventDispatcher->dispatch(new PluginDiscoveredEvent($pluginPath, $manifest));
            $this->eventDispatcher->dispatch(new PluginRegisteredEvent($plugin));
        }

        // Persist to database
        $this->pluginRepository->save($plugin);

        // Initialize default settings from config_schema immediately after registration
        if ($plugin->getState() === PluginStateEnum::REGISTERED) {
            try {
                $initializedSettings = $this->settingService->initializeDefaults($plugin);
                if ($initializedSettings > 0) {
                    $this->logger->info("Initialized $initializedSettings default settings during registration for plugin {$plugin->getName()}");
                }
            } catch (Exception $e) {
                // Log error but don't fail registration
                $this->logger->error("Failed to initialize settings during registration: {$e->getMessage()}", [
                    'plugin' => $plugin->getName(),
                ]);
            }
        }

        return $plugin;
    }

    public function enablePlugin(Plugin $plugin): void
    {
        // Validate state transition
        try {
            $this->stateMachine->validateTransition($plugin, PluginStateEnum::ENABLED);
        } catch (InvalidStateTransitionException $e) {
            // Provide helpful error for DISCOVERED state
            if ($plugin->getState() === PluginStateEnum::DISCOVERED) {
                throw new InvalidStateTransitionException(
                    $plugin->getName(),
                    PluginStateEnum::DISCOVERED,
                    PluginStateEnum::ENABLED,
                    "Plugin must be REGISTERED first. " .
                    "This usually indicates a registration failure. " .
                    "Check plugin compatibility and composer.json validation."
                );
            }
            throw $e;
        }

        // Validate dependencies
        $dependencyErrors = $this->dependencyResolver->validateDependencies($plugin);

        if (!empty($dependencyErrors)) {
            $errorMessage = sprintf(
                "Cannot enable plugin '%s' due to unmet dependencies:\n- %s",
                $plugin->getName(),
                implode("\n- ", $dependencyErrors)
            );

            $this->eventDispatcher->dispatch(
                new PluginEnablementFailedEvent(null, $plugin->getName(), $errorMessage, [])
            );

            $this->logger->warning("Plugin enablement failed: {$plugin->getName()}", [
                'errors' => $dependencyErrors,
            ]);

            throw new PluginDependencyException($errorMessage);
        }

        // Check for circular dependencies
        if ($this->dependencyResolver->hasCircularDependency($plugin)) {
            $path = $this->dependencyResolver->getCircularDependencyPath($plugin);
            $errorMessage = sprintf(
                "Cannot enable plugin '%s': Circular dependency detected: %s",
                $plugin->getName(),
                implode(' → ', $path ?? [$plugin->getName()])
            );

            $this->eventDispatcher->dispatch(
                new PluginEnablementFailedEvent(null, $plugin->getName(), $errorMessage, [])
            );

            $this->logger->warning("Circular dependency detected: {$plugin->getName()}", [
                'path' => $path,
            ]);

            throw new PluginDependencyException($errorMessage);
        }

        // Composer dependencies validation - check before security validation
        if ($this->composerManager->requiresDependencies($plugin)) {
            $this->logger->info("Plugin requires Composer dependencies", [
                'plugin' => $plugin->getName(),
            ]);

            // Step 1: Validate composer.lock exists
            if (!$this->composerManager->hasComposerLock($plugin)) {
                $errorMessage = sprintf(
                    "Cannot enable plugin '%s': composer.lock file is missing.\n" .
                    "Plugin has package dependencies but no lock file.\n" .
                    "Run 'composer install' in plugin directory and commit composer.lock.",
                    $plugin->getName()
                );

                $this->eventDispatcher->dispatch(
                    new PluginEnablementFailedEvent(null, $plugin->getName(), $errorMessage, [])
                );

                $this->logger->error("Plugin enablement blocked: composer.lock missing", [
                    'plugin' => $plugin->getName(),
                ]);

                throw new RuntimeException($errorMessage);
            }

            // Step 2: Auto-install if vendor/ directory is missing
            if (!$this->composerManager->hasVendorDirectory($plugin)) {
                $this->logger->info("Auto-installing Composer dependencies", [
                    'plugin' => $plugin->getName(),
                ]);

                try {
                    // Attempt automatic installation (no clean flag)
                    $this->composerManager->installDependencies($plugin, false);

                    $this->logger->info("Composer dependencies auto-installed successfully", [
                        'plugin' => $plugin->getName(),
                    ]);
                } catch (\Exception $e) {
                    // Installation failed - block enablement with helpful error
                    $errorMessage = sprintf(
                        "Cannot enable plugin '%s': Failed to install Composer dependencies.\n\n" .
                        "Error: %s\n\n" .
                        "You can try manual installation:\n" .
                        "php bin/console plugin:install-deps %s",
                        $plugin->getName(),
                        $e->getMessage(),
                        $plugin->getName()
                    );

                    $this->eventDispatcher->dispatch(
                        new PluginEnablementFailedEvent(null, $plugin->getName(), $errorMessage, [])
                    );

                    $this->logger->error("Auto-installation of Composer dependencies failed", [
                        'plugin' => $plugin->getName(),
                        'error' => $e->getMessage(),
                    ]);

                    throw new RuntimeException($errorMessage, 0, $e);
                }
            } else {
                $this->logger->info("Composer dependencies already installed", [
                    'plugin' => $plugin->getName(),
                ]);
            }
        } else {
            $this->logger->info("Plugin does not require Composer dependencies", [
                'plugin' => $plugin->getName(),
            ]);
        }

        // Security validation - check for critical security issues
        $securityCheckResult = $this->securityValidator->validate($plugin);
        $criticalIssues = array_filter($securityCheckResult->issues, fn($issue) => $issue['severity'] === 'critical');

        if (!empty($criticalIssues)) {
            $errorMessage = sprintf(
                "Cannot enable plugin '%s' due to critical security issues:\n- %s",
                $plugin->getName(),
                implode("\n- ", array_map(fn($issue) => $issue['message'], $criticalIssues))
            );

            // Mark plugin as faulted due to security issues
            $this->stateMachine->transitionToFaulted($plugin, $errorMessage);
            $this->pluginRepository->save($plugin);

            $this->eventDispatcher->dispatch(
                new PluginEnablementFailedEvent(null, $plugin->getName(), $errorMessage, [])
            );
            $this->eventDispatcher->dispatch(new PluginFaultedEvent($plugin, $errorMessage));

            $this->logger->error("Plugin enablement blocked due to security issues: {$plugin->getName()}", [
                'critical_issues' => $criticalIssues,
            ]);

            throw new RuntimeException($errorMessage);
        }

        // Log high severity issues as warnings (but allow plugin to be enabled)
        $highIssues = array_filter($securityCheckResult->issues, fn($issue) => $issue['severity'] === 'high');
        if (!empty($highIssues)) {
            $this->logger->warning("Plugin has high severity security issues: {$plugin->getName()}", [
                'high_issues' => $highIssues,
            ]);
        }

        // Transition to ENABLED state
        $this->stateMachine->transitionToEnabled($plugin);

        // Persist changes
        $this->pluginRepository->save($plugin);

        // Load plugin (register autoloading, services, etc.)
        try {
            $this->pluginLoader->load($plugin);

            // Initialize default settings from config_schema (skip if already initialized during registration)
            $initializedSettings = $this->settingService->initializeDefaults($plugin);
            if ($initializedSettings > 0) {
                $this->logger->info("Initialized $initializedSettings additional settings for plugin {$plugin->getName()}");
            }

            // Execute database migrations
            $migrationResult = $this->migrationService->executeMigrations($plugin);

            if (!$migrationResult['skipped'] && $migrationResult['executed'] > 0) {
                $this->logger->info("Executed {$migrationResult['executed']} migrations for plugin {$plugin->getName()}");
            }

            // Publish plugin assets
            $this->assetManager->publishAssets($plugin);

        } catch (Exception $e) {
            // If loading or migrations fail, mark as faulted
            $this->stateMachine->transitionToFaulted($plugin, $e->getMessage());
            $this->pluginRepository->save($plugin);

            $this->eventDispatcher->dispatch(new PluginFaultedEvent($plugin, $e->getMessage()));

            throw new RuntimeException("Failed to load plugin {$plugin->getName()}: {$e->getMessage()}", 0, $e);
        }

        // Dispatch event
        $this->eventDispatcher->dispatch(new PluginEnabledEvent($plugin));

        $this->logger->info("Plugin enabled: {$plugin->getName()}");

        // Clear cache to reload routes, entities, etc.
        $this->clearCache();

        // Rebuild enabled plugins cache for container compilation
        $this->cacheManager->rebuildCache();
    }

    /**
     * Disables a plugin and optionally all plugins that depend on it.
     *
     * @param Plugin $plugin The plugin to disable
     * @param bool $cascade If true, also disable all dependent plugins
     * @throws InvalidStateTransitionException If plugin cannot be disabled
     * @throws PluginDependencyException If plugin has dependents and cascade is false
     */
    public function disablePlugin(Plugin $plugin, bool $cascade = false): void
    {
        // Validate state transition
        $this->stateMachine->validateTransition($plugin, PluginStateEnum::DISABLED);

        // Find plugins that depend on this one
        $dependents = $this->dependencyResolver->getDependents($plugin);

        // Filter to only enabled dependents
        $enabledDependents = array_filter($dependents, fn($p) => $p->isEnabled());

        if (!empty($enabledDependents) && !$cascade) {
            $dependentNames = array_map(
                fn($p) => sprintf("'%s'", $p->getDisplayName()),
                $enabledDependents
            );

            $errorMessage = sprintf(
                "Cannot disable plugin '%s' because the following plugins depend on it: %s.\n" .
                "Use cascade option to disable all dependent plugins.",
                $plugin->getDisplayName(),
                implode(', ', $dependentNames)
            );

            $this->eventDispatcher->dispatch(
                new PluginDisablementFailedEvent(null, $plugin->getName(), $errorMessage, [])
            );

            $this->logger->warning("Plugin disablement failed: {$plugin->getName()}", [
                'dependents' => array_map(fn($p) => $p->getName(), $enabledDependents),
            ]);

            throw new PluginDependencyException($errorMessage);
        }

        // Disable dependents first (if cascade)
        if ($cascade && !empty($enabledDependents)) {
            $this->logger->info("Cascade disabling {count} dependent plugins", [
                'count' => count($enabledDependents),
                'plugin' => $plugin->getName(),
            ]);

            foreach ($enabledDependents as $dependent) {
                $this->logger->info("Cascade disabling dependent plugin: {$dependent->getName()}");
                $this->disablePlugin($dependent, true); // Recursive cascade
            }
        }

        // Unload plugin (unregister autoloading, etc.)
        $this->pluginLoader->unload($plugin);

        // Unpublish plugin assets
        $this->assetManager->unpublishAssets($plugin);

        // Transition to DISABLED state
        $this->stateMachine->transitionToDisabled($plugin);

        // Persist changes
        $this->pluginRepository->save($plugin);

        // Dispatch event
        $this->eventDispatcher->dispatch(new PluginDisabledEvent($plugin));

        $this->logger->info("Plugin disabled: {$plugin->getName()}");

        // Clear cache to reload routes, entities, etc.
        $this->clearCache();

        // Rebuild enabled plugins cache for container compilation
        $this->cacheManager->rebuildCache();
    }

    /**
     * Reset a plugin to REGISTERED state.
     *
     * Supported transitions:
     * - FAULTED → REGISTERED: Retry enablement after fixing the issue
     * - DISCOVERED → REGISTERED: Fix plugins stuck in discovered state
     *
     * @param Plugin $plugin The plugin to reset
     * @throws InvalidStateTransitionException If transition is not allowed
     */
    public function resetPlugin(Plugin $plugin): void
    {
        $currentState = $plugin->getState();

        // Validate state transition to REGISTERED
        $this->stateMachine->validateTransition($plugin, PluginStateEnum::REGISTERED);

        $oldFaultReason = $plugin->getFaultReason();

        // Transition to REGISTERED state
        $this->stateMachine->transitionToRegistered($plugin);

        // Clear fault reason (if any)
        $plugin->setFaultReason(null);

        // Persist changes
        $this->pluginRepository->save($plugin);

        $this->logger->info("Plugin reset to REGISTERED: {$plugin->getName()}", [
            'previous_state' => $currentState->value,
            'previous_fault_reason' => $oldFaultReason,
        ]);
    }

    /**
     * Delete a plugin completely (database + optionally filesystem).
     *
     * This operation:
     * - Checks for enabled dependent plugins (blocks deletion if found)
     * - Disables the plugin if it's enabled (unloads, unpublishes assets)
     * - Deletes plugin directory from filesystem (if $removeFiles is true)
     * - Deletes all plugin settings from database
     * - Removes plugin database record
     *
     * @param Plugin $plugin The plugin to delete
     * @param bool $removeFiles Whether to delete plugin files from filesystem (default: true)
     * @throws PluginDependencyException If enabled plugins depend on this one
     * @throws RuntimeException If filesystem deletion fails
     */
    public function deletePlugin(Plugin $plugin, bool $removeFiles = true): void
    {
        // 1. Check for enabled dependents (fail fast before any changes)
        $dependents = $this->dependencyResolver->getDependents($plugin);
        $enabledDependents = array_filter($dependents, fn($p) => $p->isEnabled());

        if (!empty($enabledDependents)) {
            $dependentNames = array_map(fn($p) => sprintf("'%s'", $p->getDisplayName()), $enabledDependents);
            throw new PluginDependencyException(
                sprintf(
                    "Cannot delete plugin '%s' because the following plugins depend on it: %s.\n" .
                    "Disable or delete these plugins first.",
                    $plugin->getDisplayName(),
                    implode(', ', $dependentNames)
                )
            );
        }

        // 2. Disable plugin FIRST if it's enabled
        if ($plugin->getState() === PluginStateEnum::ENABLED) {
            $this->logger->info("Disabling plugin before deletion: {$plugin->getName()}");
            try {
                $this->disablePlugin($plugin);
            } catch (Exception $e) {
                $this->logger->error("Failed to disable plugin before deletion", [
                    'plugin' => $plugin->getName(),
                    'error' => $e->getMessage(),
                ]);
                throw new RuntimeException(
                    sprintf(
                        "Failed to disable plugin '%s' before deletion: %s",
                        $plugin->getDisplayName(),
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }

        // 3. Remove plugin from database
        $this->pluginRepository->remove($plugin);
        $this->logger->info("Removed plugin from database: {$plugin->getName()}");

        // 4. Delete all plugin settings
        $this->settingService->deleteAll($plugin->getName());

        // 5. Delete plugin directory from filesystem (if requested)
        if ($removeFiles) {
            $pluginPath = $this->kernel->getProjectDir() . '/plugins/' . $plugin->getName();

            if (file_exists($pluginPath)) {
                try {
                    $this->removeDirectory($pluginPath);
                    $this->logger->info("Deleted plugin directory: {$plugin->getName()}", [
                        'path' => $pluginPath,
                    ]);
                } catch (Exception $e) {
                    $this->logger->critical("Failed to delete plugin directory after DB cleanup - manual cleanup required", [
                        'plugin' => $plugin->getName(),
                        'path' => $pluginPath,
                        'error' => $e->getMessage(),
                    ]);

                    // Still throw exception to inform user
                    throw new RuntimeException(
                        sprintf(
                            "Plugin '%s' was removed from database, but failed to delete directory '%s': %s\n" .
                            "Manual cleanup of the plugin directory is required.",
                            $plugin->getDisplayName(),
                            $pluginPath,
                            $e->getMessage()
                        ),
                        0,
                        $e
                    );
                }
            } else {
                $this->logger->warning("Plugin directory already missing during deletion", [
                    'plugin' => $plugin->getName(),
                    'path' => $pluginPath,
                ]);
            }
        } else {
            $this->logger->info("Skipped filesystem deletion for plugin: {$plugin->getName()}", [
                'removeFiles' => false,
            ]);
        }

        // 6. Clear cache and rebuild
        $this->clearCache();
        $this->cacheManager->rebuildCache();
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * @param string $dir Directory path to remove
     * @throws Exception If deletion fails
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            $itemPath = $item->getPathname();

            if ($item->isDir()) {
                $this->removeDirectory($itemPath);
                // Check if directory still exists after recursive deletion
                if (is_dir($itemPath)) {
                    if (!@rmdir($itemPath)) {
                        throw new RuntimeException("Failed to remove directory: {$itemPath}");
                    }
                }
            } else {
                // Check if file exists before trying to delete
                if (file_exists($itemPath)) {
                    if (!@unlink($itemPath)) {
                        throw new RuntimeException("Failed to remove file: {$itemPath}");
                    }
                }
            }
        }

        // Remove the directory itself if it still exists
        if (is_dir($dir)) {
            if (!@rmdir($dir)) {
                throw new RuntimeException("Failed to remove directory: {$dir}");
            }
        }
    }

    private function handlePluginUpdate(Plugin $plugin, PluginManifestDTO $newManifest): void
    {
        $oldVersion = $plugin->getVersion();
        $newVersion = $newManifest->version;

        // Update plugin entity
        $plugin->setVersion($newVersion);
        $plugin->setManifest($newManifest->raw);

        // Transition to UPDATE_PENDING if plugin is enabled
        if ($plugin->getState() === PluginStateEnum::ENABLED) {
            $this->stateMachine->transitionToUpdatePending($plugin);
        }

        // Persist changes
        $this->pluginRepository->save($plugin);

        // Dispatch event
        $this->eventDispatcher->dispatch(new PluginUpdatedEvent($plugin, $oldVersion, $newVersion));

        $this->logger->info("Plugin updated: {$plugin->getName()} $oldVersion → $newVersion");
    }

    public function getPluginByName(string $name): ?Plugin
    {
        return $this->pluginRepository->findByName($name);
    }

    /**
     * @return Plugin[] Array of all plugins
     */
    public function getAllPlugins(): array
    {
        return $this->pluginRepository->findAll();
    }

    /**
     * @return Plugin[] Array of enabled plugins
     */
    public function getEnabledPlugins(): array
    {
        return $this->pluginRepository->findEnabled();
    }

    /**
     * @return Plugin[] Array of disabled plugins
     */
    public function getDisabledPlugins(): array
    {
        return $this->pluginRepository->findDisabled();
    }

    /**
     * @return Plugin[] Array of faulted plugins
     */
    public function getFaultedPlugins(): array
    {
        return $this->pluginRepository->findFaulted();
    }

    /**
     * Check if a plugin with the given name exists in the database.
     *
     * This method is part of the public API and may be used by plugins
     * to check for dependencies or by other parts of the system.
     *
     * @api
     * @param string $name Plugin name
     * @return bool True if plugin exists, false otherwise
     */
    public function hasPlugin(string $name): bool
    {
        return $this->pluginRepository->existsByName($name);
    }

    /**
     * @return array{total: int, enabled: int, disabled: int, faulted: int} Plugin statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => $this->pluginRepository->count(),
            'enabled' => $this->pluginRepository->countByState(PluginStateEnum::ENABLED),
            'disabled' => $this->pluginRepository->countByState(PluginStateEnum::DISABLED),
            'faulted' => $this->pluginRepository->countByState(PluginStateEnum::FAULTED),
        ];
    }

    /**
     * @return Plugin[] Array of Plugin entities
     */
    public function getAllPluginsFromFilesystem(): array
    {
        $plugins = [];
        $scannedPlugins = $this->pluginScanner->scanValid();

        foreach ($scannedPlugins as $pluginName => $data) {
            $manifest = $data['manifest'];
            $pluginPath = $data['path'];

            // Check if plugin exists in database
            $existingPlugin = $this->pluginRepository->findByName($pluginName);

            if ($existingPlugin !== null) {
                // Check for version update
                if ($existingPlugin->getVersion() !== $manifest->version) {
                    $existingPlugin->setVersion($manifest->version);
                    $existingPlugin->setManifest($manifest->raw);
                    if ($existingPlugin->getState() === PluginStateEnum::ENABLED) {
                        $existingPlugin->setState(PluginStateEnum::UPDATE_PENDING);
                    }
                }
                $plugins[] = $existingPlugin;
            } else {
                // Auto-register plugin found on filesystem but not in database
                try {
                    $plugin = $this->registerPlugin($pluginPath, $manifest);
                    $this->logger->info("Auto-registered plugin discovered in filesystem", [
                        'plugin' => $manifest->name,
                        'state' => $plugin->getState()->value,
                    ]);
                } catch (Exception $e) {
                    // If registration fails, fall back to virtual entity for display
                    $this->logger->warning("Failed to auto-register plugin, showing as virtual entity", [
                        'plugin' => $manifest->name,
                        'error' => $e->getMessage(),
                    ]);
                    $plugin = $this->createPluginFromManifest($pluginPath, $manifest);
                }
                $plugins[] = $plugin;
            }
        }

        return $plugins;
    }

    /**
     * @throws RuntimeException If plugin not found in filesystem
     */
    public function getOrCreatePlugin(string $pluginName): Plugin
    {
        // Check database first
        $plugin = $this->pluginRepository->findByName($pluginName);
        if ($plugin !== null) {
            return $plugin;
        }

        // Scan filesystem
        $pluginData = $this->pluginScanner->discoverPlugin(
            $this->pluginScanner->getPluginPath($pluginName)
        );

        if ($pluginData === null) {
            throw new RuntimeException("Plugin '$pluginName' not found in filesystem");
        }

        if (count($pluginData['errors']) > 0) {
            throw new RuntimeException(
                "Plugin '$pluginName' has validation errors: " . implode(', ', $pluginData['errors'])
            );
        }

        // Create and register plugin entity from manifest
        // This ensures proper state transition (DISCOVERED -> REGISTERED or FAULTED)
        $plugin = $this->registerPlugin($pluginData['path'], $pluginData['manifest']);

        $this->logger->info("Created plugin entity: $pluginName");

        return $plugin;
    }

    private function createPluginFromManifest(string $pluginPath, PluginManifestDTO $manifest): Plugin
    {
        $plugin = $this->createPluginEntityFromManifest($pluginPath, $manifest);
        $plugin->setState(PluginStateEnum::DISCOVERED);

        // Validate PteroCA compatibility
        if (!$this->manifestValidator->isCompatibleWithPteroCA($manifest)) {
            $errorMessage = $this->manifestValidator->getCompatibilityError($manifest);
            $plugin->setState(PluginStateEnum::FAULTED);
            $plugin->setFaultReason($errorMessage);
        }

        return $plugin;
    }

    /**
     * Create a basic Plugin entity from manifest data.
     * Does not set state or perform validation.
     */
    private function createPluginEntityFromManifest(string $pluginPath, PluginManifestDTO $manifest): Plugin
    {
        $plugin = new Plugin();
        $plugin->setName($manifest->name);
        $plugin->setDisplayName($manifest->displayName);
        $plugin->setVersion($manifest->version);
        $plugin->setAuthor($manifest->author);
        $plugin->setDescription($manifest->description);
        $plugin->setLicense($manifest->license);
        $plugin->setPterocaMinVersion($manifest->getMinPterocaVersion());
        $plugin->setPterocaMaxVersion($manifest->getMaxPterocaVersion());
        $plugin->setPath($pluginPath);
        $plugin->setManifest($manifest->raw);

        return $plugin;
    }

    private function clearCache(): void
    {
        $cacheDir = $this->kernel->getCacheDir();

        // Register shutdown function to clear cache after script completes
        // This is the safest way to avoid errors when deleting files that are currently in use
        register_shutdown_function(function () use ($cacheDir) {
            // Recursive function to remove directory contents
            $removeDir = function (string $dir) use (&$removeDir) {
                if (!is_dir($dir)) {
                    return;
                }

                $items = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);

                foreach ($items as $item) {
                    if ($item->isDir()) {
                        $removeDir($item->getPathname());
                        @rmdir($item->getPathname());
                    } else {
                        // Don't remove .gitkeep files
                        if ($item->getFilename() !== '.gitkeep') {
                            @unlink($item->getPathname());
                        }
                    }
                }
            };

            try {
                $removeDir($cacheDir);
            } catch (Throwable) {
                // Silently fail - cache will be rebuilt on next request
            }
        });

        $this->logger->info("Scheduled cache clearing for after process completion");
    }
}
