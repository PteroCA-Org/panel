<?php

namespace App\Core\Service\Plugin;

use App\Core\Entity\Plugin;
use App\Core\Entity\Setting;
use App\Core\Repository\SettingRepository;
use App\Core\Service\SettingTypeMapperService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for managing plugin-specific settings.
 *
 * Provides a simple API for plugins to store and retrieve configuration values
 * using the existing Setting entity with plugin-specific context.
 *
 * Settings are namespaced by plugin and stored with context = "plugin:{plugin-name}"
 */
readonly class PluginSettingService
{
    public function __construct(
        private SettingRepository        $settingRepository,
        private EntityManagerInterface   $entityManager,
        private LoggerInterface          $logger,
        private SettingTypeMapperService $typeMapper,
    ) {}

    /**
     * Get a plugin setting value.
     *
     * @param string $pluginName Plugin name
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value or default
     */
    public function get(string $pluginName, string $key, mixed $default = null): mixed
    {
        $context = $this->buildContext($pluginName);

        $setting = $this->settingRepository->findOneBy([
            'name' => $key,
            'context' => $context,
        ]);

        if ($setting === null) {
            return $default;
        }

        return $this->convertValue($setting->getValue(), $setting->getType());
    }

    /**
     * Set a plugin setting value.
     *
     * Creates a new setting if it doesn't exist, updates existing one otherwise.
     *
     * @param string $pluginName Plugin name
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @param string|null $type Setting type (string, integer, boolean, etc.), auto-detected if null
     * @param int|null $hierarchy Hierarchy level for grouping settings
     * @throws RuntimeException If setting cannot be saved
     */
    public function set(string $pluginName, string $key, mixed $value, ?string $type = null, ?int $hierarchy = null): void
    {
        $context = $this->buildContext($pluginName);

        $setting = $this->settingRepository->findOneBy([
            'name' => $key,
            'context' => $context,
        ]);

        // Auto-detect type if not provided
        if ($type === null) {
            $type = $this->detectType($value);
        }

        // Map config_schema storage type to SettingTypeEnum display type
        $type = $this->typeMapper->toDisplayType($type);

        // Convert value to string for storage
        $stringValue = $this->serializeValue($value, $type);

        if ($setting === null) {
            // Create new setting
            $setting = new Setting();
            $setting->setName($key);
            $setting->setContext($context);
            $setting->setType($type);
            $setting->setValue($stringValue);

            if ($hierarchy !== null) {
                $setting->setHierarchy($hierarchy);
            }

            $this->entityManager->persist($setting);
        } else {
            // Update existing setting
            $setting->setValue($stringValue);
            $setting->setType($type);

            if ($hierarchy !== null) {
                $setting->setHierarchy($hierarchy);
            }
        }

        try {
            $this->entityManager->flush();

            $this->logger->debug('Plugin setting saved', [
                'plugin' => $pluginName,
                'key' => $key,
                'type' => $type,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save plugin setting', [
                'plugin' => $pluginName,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                sprintf('Failed to save plugin setting "%s" for plugin "%s"', $key, $pluginName),
                0,
                $e
            );
        }
    }

    /**
     * Get all settings for a plugin.
     *
     * @param string $pluginName Plugin name
     * @return array<string, mixed> Array of settings indexed by key
     */
    public function getAll(string $pluginName): array
    {
        $context = $this->buildContext($pluginName);

        $settings = $this->settingRepository->findBy([
            'context' => $context,
        ], ['hierarchy' => 'ASC', 'name' => 'ASC']);

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->getName()] = $this->convertValue(
                $setting->getValue(),
                $setting->getType()
            );
        }

        return $result;
    }

    /**
     * Check if a setting exists.
     *
     * @param string $pluginName Plugin name
     * @param string $key Setting key
     * @return bool
     */
    public function has(string $pluginName, string $key): bool
    {
        $context = $this->buildContext($pluginName);

        return $this->settingRepository->findOneBy([
            'name' => $key,
            'context' => $context,
        ]) !== null;
    }

    /**
     * Delete a plugin setting.
     *
     * @param string $pluginName Plugin name
     * @param string $key Setting key
     * @return bool True if setting was deleted, false if it didn't exist
     */
    public function delete(string $pluginName, string $key): bool
    {
        $context = $this->buildContext($pluginName);

        $setting = $this->settingRepository->findOneBy([
            'name' => $key,
            'context' => $context,
        ]);

        if ($setting === null) {
            return false;
        }

        try {
            $this->entityManager->remove($setting);
            $this->entityManager->flush();

            $this->logger->info('Plugin setting deleted', [
                'plugin' => $pluginName,
                'key' => $key,
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete plugin setting', [
                'plugin' => $pluginName,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete all settings for a plugin.
     *
     * Useful when uninstalling a plugin.
     *
     * @param string $pluginName Plugin name
     * @return int Number of settings deleted
     */
    public function deleteAll(string $pluginName): int
    {
        $context = $this->buildContext($pluginName);

        try {
            $qb = $this->entityManager->createQueryBuilder();
            $deleted = $qb->delete(Setting::class, 's')
                ->where('s.context = :context')
                ->setParameter('context', $context)
                ->getQuery()
                ->execute();

            $this->logger->info('All plugin settings deleted', [
                'plugin' => $pluginName,
                'count' => $deleted,
            ]);

            return $deleted;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete all plugin settings', [
                'plugin' => $pluginName,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Initialize default settings from plugin manifest config_schema.
     *
     * This is called when a plugin is enabled. Only creates settings that don't exist yet,
     * preserving any existing values.
     *
     * @param Plugin $plugin Plugin entity
     * @return int Number of settings initialized
     */
    public function initializeDefaults(Plugin $plugin): int
    {
        $configSchema = $plugin->getConfigSchema();

        if (empty($configSchema)) {
            $this->logger->debug('No config schema found for plugin', [
                'plugin' => $plugin->getName(),
            ]);
            return 0;
        }

        $initialized = 0;

        foreach ($configSchema as $key => $schema) {
            // Skip if setting already exists
            if ($this->has($plugin->getName(), $key)) {
                $this->logger->debug('Setting already exists, skipping', [
                    'plugin' => $plugin->getName(),
                    'key' => $key,
                ]);
                continue;
            }

            $defaultValue = $schema['default'] ?? null;
            $storageType = $schema['type'] ?? 'string';
            $type = $this->typeMapper->toDisplayType($storageType);
            $hierarchy = $schema['hierarchy'] ?? 100;

            // Validate hierarchy
            if (is_string($hierarchy)) {
                // Convert hierarchy names to numbers
                $hierarchy = match ($hierarchy) {
                    'advanced' => 200,
                    'api' => 300,
                    default => 100,
                };
            }

            try {
                $this->set(
                    $plugin->getName(),
                    $key,
                    $defaultValue,
                    $type,
                    $hierarchy
                );

                $initialized++;

                $this->logger->info('Initialized plugin setting from config schema', [
                    'plugin' => $plugin->getName(),
                    'key' => $key,
                    'type' => $type,
                    'hierarchy' => $hierarchy,
                ]);
            } catch (Exception $e) {
                $this->logger->error('Failed to initialize plugin setting', [
                    'plugin' => $plugin->getName(),
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $initialized;
    }

    /**
     * Validate a setting value against the plugin's config schema.
     *
     * @param Plugin $plugin Plugin entity
     * @param string $key Setting key
     * @param mixed $value Value to validate
     * @return bool True if valid, false otherwise
     */
    public function validateAgainstSchema(Plugin $plugin, string $key, mixed $value): bool
    {
        $configSchema = $plugin->getConfigSchema();

        if (!isset($configSchema[$key])) {
            $this->logger->warning('Setting key not found in config schema', [
                'plugin' => $plugin->getName(),
                'key' => $key,
            ]);
            return false;
        }

        $schema = $configSchema[$key];
        $expectedType = $schema['type'] ?? 'string';

        // Type validation
        $actualType = $this->detectType($value);
        if ($actualType !== $expectedType) {
            $this->logger->warning('Setting value type mismatch', [
                'plugin' => $plugin->getName(),
                'key' => $key,
                'expected' => $expectedType,
                'actual' => $actualType,
            ]);
            return false;
        }

        // Additional validation rules can be added here
        // e.g., min/max values, regex patterns, enum values, etc.

        return true;
    }

    /**
     * Build plugin context string.
     *
     * @param string $pluginName Plugin name
     * @return string Context string (format: "plugin:{plugin-name}")
     */
    private function buildContext(string $pluginName): string
    {
        return "plugin:$pluginName";
    }

    /**
     * Detect value type for storage.
     *
     * @param mixed $value Value to detect type for
     * @return string Type name (string, integer, boolean, json)
     */
    private function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value), is_object($value) => 'json',
            default => 'string',
        };
    }

    /**
     * Serialize value for storage.
     *
     * @param mixed $value Value to serialize
     * @param string $type Value type
     * @return string|null Serialized value
     */
    private function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Convert stored value to proper type.
     *
     * @param string|null $value Stored value
     * @param string $type Value type
     * @return mixed Converted value
     */
    private function convertValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === '1' || $value === 'true',
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
