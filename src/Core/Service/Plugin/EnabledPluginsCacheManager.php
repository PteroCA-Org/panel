<?php

declare(strict_types=1);

namespace App\Core\Service\Plugin;

use App\Core\Repository\PluginRepository;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Manages the cache file for enabled plugins.
 *
 * This cache is used by the PluginCompilerPass to determine which plugins
 * to load during container compilation.
 */
class EnabledPluginsCacheManager
{
    private const CACHE_FILENAME = 'enabled_plugins.php';

    public function __construct(
        private readonly PluginRepository $pluginRepository,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    /**
     * Rebuild the enabled plugins cache from database.
     *
     * @return bool True if cache was successfully updated
     */
    public function rebuildCache(): bool
    {
        try {
            $enabledPlugins = $this->pluginRepository->findEnabled();
            $pluginNames = array_map(fn($plugin) => $plugin->getName(), $enabledPlugins);

            return $this->writeCacheFile($pluginNames);
        } catch (Exception $e) {
            $this->logger->error('Failed to rebuild enabled plugins cache', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Write enabled plugins list to cache file.
     *
     * @param array $pluginNames Array of enabled plugin names
     * @return bool True if successful
     */
    private function writeCacheFile(array $pluginNames): bool
    {
        $cacheDir = $this->projectDir . '/var/cache';
        $cacheFile = $cacheDir . '/' . self::CACHE_FILENAME;

        // Ensure cache directory exists
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            $this->logger->error('Failed to create cache directory', ['path' => $cacheDir]);
            return false;
        }

        // Generate PHP cache file content
        $content = "<?php\n\n";
        $content .= "// Auto-generated cache of enabled plugins\n";
        $content .= "// This file is used by PluginCompilerPass during container compilation\n";
        $content .= "// Last updated: " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "return " . var_export($pluginNames, true) . ";\n";

        try {
            $written = file_put_contents($cacheFile, $content, LOCK_EX);

            if ($written === false) {
                $this->logger->error('Failed to write enabled plugins cache', ['file' => $cacheFile]);
                return false;
            }

            $this->logger->info('Enabled plugins cache updated', [
                'file' => $cacheFile,
                'count' => count($pluginNames),
                'plugins' => $pluginNames,
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Exception while writing enabled plugins cache', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clear the enabled plugins cache.
     *
     * @return bool True if successful
     */
    public function clearCache(): bool
    {
        $cacheFile = $this->projectDir . '/var/cache/' . self::CACHE_FILENAME;

        if (!file_exists($cacheFile)) {
            return true; // Already cleared
        }

        try {
            $deleted = unlink($cacheFile);

            if ($deleted) {
                $this->logger->info('Enabled plugins cache cleared');
            }

            return $deleted;
        } catch (Exception $e) {
            $this->logger->error('Failed to clear enabled plugins cache', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
