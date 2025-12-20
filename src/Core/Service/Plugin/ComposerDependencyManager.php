<?php

namespace App\Core\Service\Plugin;

use App\Core\Entity\Plugin;
use App\Core\Service\Composer\ComposerBinaryResolverService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Manages Composer dependencies for plugins.
 *
 * This service handles installation, validation, and verification
 * of Composer dependencies in isolated per-plugin vendor directories.
 */
readonly class ComposerDependencyManager
{
    private string $composerBinary;

    public function __construct(
        private string $projectDir,
        private LoggerInterface $logger,
        ComposerBinaryResolverService $composerResolver
    ) {
        $this->composerBinary = $composerResolver->resolveComposerBinary();
    }

    /**
     * Install Composer dependencies for a plugin.
     *
     * Executes composer install with production flags:
     * - --no-dev (exclude development dependencies)
     * - --no-interaction (no prompts)
     * - --no-plugins (disable Composer plugins for security)
     * - --no-scripts (disable scripts for security)
     * - --prefer-dist (use distribution packages)
     * - --classmap-authoritative (optimize autoloader)
     *
     * @param Plugin $plugin Plugin to install dependencies for
     * @param bool $clean Remove vendor/ directory before installation
     * @throws \RuntimeException If installation fails
     */
    public function installDependencies(Plugin $plugin, bool $clean = false): void
    {
        $pluginPath = $this->getPluginPath($plugin);
        $vendorPath = $pluginPath . '/vendor';

        // Clean install - remove existing vendor directory
        if ($clean && is_dir($vendorPath)) {
            $this->logger->info('Removing existing vendor directory', [
                'plugin' => $plugin->getName(),
                'path' => $vendorPath,
            ]);

            $this->removeDirectory($vendorPath);
        }

        $this->logger->info('Installing Composer dependencies', [
            'plugin' => $plugin->getName(),
            'clean' => $clean,
        ]);

        // Execute composer install with production and security flags
        $process = new Process(
            [
                $this->composerBinary,
                'install',
                '--no-dev',              // Exclude development dependencies
                '--no-interaction',      // No prompts
                '--no-progress',         // No progress bar (cleaner logs)
                '--no-plugins',          // Disable Composer plugins (security)
                '--no-scripts',          // Disable scripts (security)
                '--prefer-dist',         // Use distribution packages
                '--classmap-authoritative', // Optimize autoloader
            ],
            $pluginPath,
            $this->buildProcessEnvironment(),  // Inherit environment variables (PATH, etc.)
            null,
            300  // 5 minute timeout
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Failed to install Composer dependencies', [
                'plugin' => $plugin->getName(),
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            throw new \RuntimeException(
                sprintf(
                    'Failed to install Composer dependencies for plugin "%s": %s',
                    $plugin->getName(),
                    $this->truncateOutput($process->getErrorOutput())
                )
            );
        }

        $this->logger->info('Composer dependencies installed successfully', [
            'plugin' => $plugin->getName(),
            'output' => $process->getOutput(),
        ]);
    }

    /**
     * Check if plugin has composer.json file.
     */
    public function hasComposerJson(Plugin $plugin): bool
    {
        $composerJsonPath = $this->getPluginPath($plugin) . '/composer.json';
        return file_exists($composerJsonPath);
    }

    /**
     * Check if plugin has composer.lock file.
     *
     * composer.lock is required for reproducible builds and security.
     */
    public function hasComposerLock(Plugin $plugin): bool
    {
        $composerLockPath = $this->getPluginPath($plugin) . '/composer.lock';
        return file_exists($composerLockPath);
    }

    /**
     * Check if plugin has installed vendor directory.
     */
    public function hasVendorDirectory(Plugin $plugin): bool
    {
        $vendorPath = $this->getPluginPath($plugin) . '/vendor';
        return is_dir($vendorPath);
    }

    /**
     * Check if plugin requires Composer package dependencies.
     * Returns false if no composer.json or only PHP/extension requirements.
     *
     * @return bool True if plugin needs vendor/ installation
     */
    public function requiresDependencies(Plugin $plugin): bool
    {
        if (!$this->hasComposerJson($plugin)) {
            return false;
        }

        $composerJsonPath = $this->getPluginPath($plugin) . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return false;
        }

        $composerData = json_decode(file_get_contents($composerJsonPath), true);

        if (empty($composerData['require'])) {
            return false;
        }

        // Filter out PHP and extensions (not actual packages)
        $packages = array_filter(
            array_keys($composerData['require']),
            fn($name) => !str_starts_with($name, 'php') && !str_starts_with($name, 'ext-')
        );

        return !empty($packages);
    }

    /**
     * Validate Composer files (composer.json and composer.lock).
     *
     * Returns array of validation issues. Empty array means all checks passed.
     *
     * @return array<array{type: string, severity: string, message: string, file?: string}>
     */
    public function validateComposerFiles(Plugin $plugin): array
    {
        $issues = [];
        $pluginPath = $this->getPluginPath($plugin);

        // Check if composer.json exists
        if (!$this->hasComposerJson($plugin)) {
            return []; // No composer.json = no validation needed
        }

        // Check if composer.lock exists
        if (!$this->hasComposerLock($plugin)) {
            $issues[] = [
                'type' => 'composer_lock_missing',
                'severity' => 'HIGH',
                'message' => 'Plugin has composer.json but no composer.lock file. Run "composer install" locally and commit composer.lock.',
                'file' => 'composer.lock',
            ];
        }

        // Validate composer.json structure
        $process = new Process(
            [$this->composerBinary, 'validate', '--no-check-publish', '--strict'],
            $pluginPath,
            $this->buildProcessEnvironment()  // Inherit environment variables (PATH, etc.)
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $issues[] = [
                'type' => 'composer_validate_failed',
                'severity' => 'HIGH',
                'message' => 'composer.json validation failed',
                'file' => 'composer.json',
                'details' => $this->truncateOutput($process->getErrorOutput()),
            ];
        }

        return $issues;
    }

    /**
     * Get installed packages from vendor/composer/installed.php.
     *
     * @return array<string, mixed> Installed package information
     */
    public function getInstalledPackages(Plugin $plugin): array
    {
        $installedPhpPath = $this->getPluginPath($plugin) . '/vendor/composer/installed.php';

        if (!file_exists($installedPhpPath)) {
            return [];
        }

        $installed = include $installedPhpPath;

        return $installed['versions'] ?? [];
    }

    /**
     * Get plugin absolute path.
     */
    private function getPluginPath(Plugin $plugin): string
    {
        return $this->projectDir . '/plugins/' . $plugin->getName();
    }

    /**
     * Truncate output for error messages (keep first 500 chars).
     */
    private function truncateOutput(string $output): string
    {
        $maxLength = 500;

        if (strlen($output) > $maxLength) {
            return substr($output, 0, $maxLength) . '... (truncated)';
        }

        return $output;
    }

    /**
     * Build environment array for Process.
     * Filters $_SERVER to include only string values (Process requirement).
     *
     * @return array<string, string>
     */
    private function buildProcessEnvironment(): array
    {
        $env = [];

        // Include essential environment variables for composer to work
        $essentialVars = ['PATH', 'HOME', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_ALLOW_SUPERUSER'];

        foreach ($essentialVars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = $value;
            }
        }

        // Also filter $_SERVER for other string values
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && !isset($env[$key])) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Recursively remove directory.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);

        foreach ($files as $file) {
            $filePath = $path . '/' . $file;

            if (is_dir($filePath)) {
                $this->removeDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }

        rmdir($path);
    }
}
