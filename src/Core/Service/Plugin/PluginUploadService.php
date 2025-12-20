<?php

namespace App\Core\Service\Plugin;

use App\Core\DTO\PluginManifestDTO;
use App\Core\Entity\Plugin;
use App\Core\Exception\Plugin\FileTooLargeException;
use App\Core\Exception\Plugin\InvalidFileExtensionException;
use App\Core\Exception\Plugin\InvalidFileTypeException;
use App\Core\Exception\Plugin\InvalidManifestException;
use App\Core\Exception\Plugin\InvalidZipFileException;
use App\Core\Exception\Plugin\MaliciousZipException;
use App\Core\Exception\Plugin\ManifestValidationException;
use App\Core\Exception\Plugin\MissingManifestException;
use App\Core\Exception\Plugin\PluginAlreadyExistsException;
use App\Core\Exception\Plugin\PluginUploadException;
use App\Core\Exception\Plugin\ZipBombException;
use Exception;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

class PluginUploadService
{
    private const MAX_FILE_SIZE = 52428800; // 50 MB
    private const MAX_EXTRACTED_SIZE = 104857600; // 100 MB
    private const ALLOWED_MIME_TYPES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-zip',
        'application/octet-stream', // Some browsers send this for ZIP
    ];

    public function __construct(
        private readonly string $pluginsDirectory,
        private readonly string $tempDirectory,
        private readonly ManifestParser $manifestParser,
        private readonly ManifestValidator $manifestValidator,
        private readonly PluginSecurityValidator $securityValidator,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        private readonly bool $enablePreScan = true,
    ) {}

    /**
     * Main upload method.
     *
     * @throws PluginUploadException|Exception
     */
    public function uploadPlugin(UploadedFile $file): array
    {
        $tempDir = null;
        $finalPath = null;

        try {
            $this->logger->info('Starting plugin upload', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);

            // 1. Validate uploaded file
            $this->validateUploadedFile($file);

            // 2. Extract to temp
            $tempDir = $this->extractZipToTemp($file);

            // 3. Validate plugin structure
            $manifest = $this->validatePluginStructure($tempDir);

            // 4. Check conflicts
            $this->checkPluginConflict($manifest->name);

            // 5. (Optional) Pre-security scan
            $securityIssues = [];
            if ($this->enablePreScan) {
                $securityIssues = $this->runSecurityScan($tempDir, $manifest);
            }

            // 6. Move to plugins directory
            $finalPath = $this->moveToPluginsDirectory($tempDir, $manifest->name);

            $this->logger->info('Plugin uploaded successfully', [
                'plugin' => $manifest->name,
                'version' => $manifest->version,
                'path' => $finalPath,
            ]);

            return [
                'success' => true,
                'manifest' => $manifest,
                'path' => $finalPath,
                'security_issues' => $securityIssues,
            ];

        } catch (Exception $e) {
            $this->logger->error('Plugin upload failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);

            // Rollback
            $this->rollback($tempDir, $finalPath);

            throw $e;
        }
    }

    /**
     * Validate uploaded file (MIME type, size, extension).
     */
    private function validateUploadedFile(UploadedFile $file): void
    {
        // Validate MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidFileTypeException(
                sprintf('Invalid file type. Expected ZIP, got %s', $mimeType)
            );
        }

        // Validate size
        $fileSize = $file->getSize();
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new FileTooLargeException(
                sprintf(
                    'File too large (%.1f MB). Maximum allowed: %.1f MB',
                    $fileSize / 1024 / 1024,
                    self::MAX_FILE_SIZE / 1024 / 1024
                )
            );
        }

        // Validate extension
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'zip') {
            throw new InvalidFileExtensionException('Only .zip files are allowed');
        }

        // Verify it's actually a ZIP (not renamed .exe)
        $zip = new ZipArchive();
        if ($zip->open($file->getPathname()) !== true) {
            throw new InvalidZipFileException('The file is not a valid ZIP archive');
        }
        $zip->close();
    }

    /**
     * Extract ZIP to temporary directory with security checks.
     */
    private function extractZipToTemp(UploadedFile $file): string
    {
        // Create unique temp directory
        $tempDir = $this->tempDirectory . '/plugin-upload-' . uniqid();
        $this->filesystem->mkdir($tempDir);

        $zip = new ZipArchive();
        if ($zip->open($file->getPathname()) !== true) {
            throw new InvalidZipFileException('Failed to open ZIP file');
        }

        try {
            // Check total extracted size (zip bomb protection)
            $totalSize = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $totalSize += $stat['size'];

                if ($totalSize > self::MAX_EXTRACTED_SIZE) {
                    throw new ZipBombException(
                        sprintf(
                            'Extracted size (%.1f MB) exceeds maximum allowed (%.1f MB)',
                            $totalSize / 1024 / 1024,
                            self::MAX_EXTRACTED_SIZE / 1024 / 1024
                        )
                    );
                }
            }

            // Validate all paths before extraction
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if ($filename === false) {
                    continue;
                }

                // Check for dangerous paths
                if ($this->isDangerousPath($filename)) {
                    throw new MaliciousZipException("Dangerous path detected: $filename");
                }

                // Check for symlinks
                $stat = $zip->statIndex($i);
                if ($stat !== false && $this->isSymlink($stat)) {
                    throw new MaliciousZipException("Symbolic link detected: $filename");
                }
            }

            // Extract all files
            if (!$zip->extractTo($tempDir)) {
                throw new InvalidZipFileException('Failed to extract ZIP file');
            }

        } finally {
            $zip->close();
        }

        return $tempDir;
    }

    /**
     * Validate plugin structure (find plugin.json and validate manifest).
     */
    private function validatePluginStructure(string $tempDir): PluginManifestDTO
    {
        // Find plugin root (might be nested in zip)
        $pluginRoot = $this->findPluginRoot($tempDir);

        if ($pluginRoot === null) {
            throw new MissingManifestException('plugin.json not found in the archive');
        }

        // Parse manifest
        try {
            $manifest = $this->manifestParser->parseFromDirectory($pluginRoot);
        } catch (Exception $e) {
            throw new InvalidManifestException('Failed to parse plugin.json: ' . $e->getMessage());
        }

        // Validate manifest
        $errors = $this->manifestValidator->validate($manifest);
        if (!empty($errors)) {
            $errorMessages = array_map(fn($error) => $error['message'], $errors);
            throw new ManifestValidationException(
                'Manifest validation failed: ' . implode(', ', $errorMessages),
                $errors
            );
        }

        return $manifest;
    }

    /**
     * Check if plugin with this name already exists.
     */
    private function checkPluginConflict(string $pluginName): void
    {
        $targetPath = $this->pluginsDirectory . '/' . $pluginName;

        if (is_dir($targetPath)) {
            throw new PluginAlreadyExistsException(
                sprintf("Plugin '%s' already exists. Please remove it first or use a different name.", $pluginName)
            );
        }
    }

    /**
     * Run security scan on plugin before moving to final location.
     * @throws ReflectionException
     */
    private function runSecurityScan(string $pluginPath, PluginManifestDTO $manifest): array
    {
        // Find actual plugin root
        $pluginRoot = $this->findPluginRoot($pluginPath);
        if ($pluginRoot === null) {
            return [];
        }

        // Create temporary Plugin entity for scanning
        $tempPlugin = new Plugin();
        $tempPlugin->setName($manifest->name);
        $tempPlugin->setManifest($manifest->toArray());
        $tempPlugin->setPterocaMinVersion($manifest->pteroca['min']);

        try {
            // Temporarily move to plugins directory for scan
            $tempScanPath = $this->pluginsDirectory . '/temp-scan-' . uniqid();
            $this->filesystem->rename($pluginRoot, $tempScanPath);

            $tempPlugin->setName(basename($tempScanPath));
            $tempPlugin->setPath($tempScanPath);
            $securityCheckResult = $this->securityValidator->validate($tempPlugin);

            // Move back
            $this->filesystem->rename($tempScanPath, $pluginRoot);

            // Check for CRITICAL issues
            $criticalIssues = array_filter($securityCheckResult->issues, fn($issue) => $issue['severity'] === 'CRITICAL');
            if (!empty($criticalIssues)) {
                $criticalMessages = array_map(fn($issue) => $issue['message'], $criticalIssues);
                throw new RuntimeException(
                    'CRITICAL security issues detected: ' . implode('; ', $criticalMessages)
                );
            }

            return $securityCheckResult->issues;

        } catch (Exception $e) {
            $this->logger->warning('Security scan failed during upload', [
                'error' => $e->getMessage(),
                'plugin' => $manifest->name,
            ]);

            // If it's a critical security exception, re-throw
            if (str_contains($e->getMessage(), 'CRITICAL')) {
                throw $e;
            }

            return [];
        }
    }

    /**
     * Move plugin from temp directory to final plugins directory.
     */
    private function moveToPluginsDirectory(string $tempDir, string $pluginName): string
    {
        $pluginRoot = $this->findPluginRoot($tempDir);
        if ($pluginRoot === null) {
            throw new RuntimeException('Plugin root not found');
        }

        $targetPath = $this->pluginsDirectory . '/' . $pluginName;

        // Move plugin directory
        $this->filesystem->rename($pluginRoot, $targetPath);

        // Set proper permissions
        $this->setPermissions($targetPath);

        // Cleanup temp directory
        if (is_dir($tempDir)) {
            $this->filesystem->remove($tempDir);
        }

        return $targetPath;
    }

    /**
     * Set proper file permissions (755 for directories, 644 for files).
     */
    private function setPermissions(string $path): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    chmod($item->getPathname(), 0755);
                } else {
                    chmod($item->getPathname(), 0644);
                }
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to set permissions', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rollback changes in case of error.
     */
    private function rollback(?string $tempDir, ?string $finalPath): void
    {
        if ($tempDir && is_dir($tempDir)) {
            try {
                $this->filesystem->remove($tempDir);
                $this->logger->debug('Rolled back temp directory', ['path' => $tempDir]);
            } catch (Exception $e) {
                $this->logger->error('Failed to rollback temp directory', [
                    'path' => $tempDir,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($finalPath && is_dir($finalPath)) {
            try {
                $this->filesystem->remove($finalPath);
                $this->logger->debug('Rolled back final directory', ['path' => $finalPath]);
            } catch (Exception $e) {
                $this->logger->error('Failed to rollback final directory', [
                    'path' => $finalPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Find plugin root directory (recursively search for plugin.json).
     */
    private function findPluginRoot(string $directory): ?string
    {
        // Check if plugin.json is in root
        if (file_exists($directory . '/plugin.json')) {
            return $directory;
        }

        // Check subdirectories (one level deep)
        $items = scandir($directory);
        if ($items === false) {
            return null;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path) && file_exists($path . '/plugin.json')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if path contains dangerous patterns.
     */
    private function isDangerousPath(string $path): bool
    {
        $dangerousPatterns = [
            '../',           // Path traversal
            '..\\',          // Windows path traversal
            "\0",            // Null byte
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }

        // Reject absolute paths
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        // Reject Windows drive letters
        if (preg_match('/^[a-zA-Z]:/', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Check if stat array indicates a symbolic link.
     */
    private function isSymlink(array $stat): bool
    {
        // Check external_attr for symlink flag (Unix: 0120000)
        if (isset($stat['external_attr'])) {
            return ($stat['external_attr'] >> 16 & 0120000) === 0120000;
        }

        return false;
    }
}
