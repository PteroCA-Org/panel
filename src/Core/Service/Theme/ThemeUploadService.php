<?php

namespace App\Core\Service\Theme;

use App\Core\DTO\TemplateManifestDTO;
use App\Core\DTO\ThemeUploadResultDTO;
use App\Core\DTO\ThemeUploadWarningDTO;
use App\Core\Exception\Plugin\FileTooLargeException;
use App\Core\Exception\Plugin\InvalidFileExtensionException;
use App\Core\Exception\Plugin\InvalidFileTypeException;
use App\Core\Exception\Plugin\InvalidZipFileException;
use App\Core\Exception\Plugin\MaliciousZipException;
use App\Core\Exception\Plugin\ZipBombException;
use App\Core\Exception\Theme\InvalidTemplateManifestException;
use App\Core\Exception\Theme\InvalidThemeStructureException;
use App\Core\Exception\Theme\ThemeAlreadyExistsException;
use App\Core\Exception\Theme\ThemeSecurityException;
use Exception;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

class ThemeUploadService
{
    private const MAX_FILE_SIZE = 52428800; // 50 MB
    private const MAX_EXTRACTED_SIZE = 104857600; // 100 MB
    private const ALLOWED_MIME_TYPES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-zip',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly string $themesDirectory,
        private readonly string $publicAssetsDirectory,
        private readonly string $tempDirectory,
        private readonly string $currentPterocaVersion,
        private readonly TemplateManifestParser $manifestParser,
        private readonly TemplateManifestValidator $manifestValidator,
        private readonly ThemeStructureValidator $structureValidator,
        private readonly ThemeSecurityValidator $securityValidator,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
    ) {}

    public function uploadTheme(UploadedFile $file, bool $ignoreWarnings = false): ThemeUploadResultDTO
    {
        $tempDir = null;
        $themePath = null;
        $assetsPath = null;

        try {
            $this->logger->info('Starting theme upload', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);

            // 1. Validate uploaded file
            $this->validateUploadedFile($file);

            // 2. Extract to temp with security checks
            $tempDir = $this->extractZipToTemp($file);

            // 3. Find and parse manifest
            $themeRoot = $this->structureValidator->findThemeRoot($tempDir);
            if ($themeRoot === null) {
                throw new InvalidThemeStructureException('Could not find themes/{name}/template.json in ZIP');
            }

            $manifest = $this->manifestParser->parseFromDirectory($themeRoot);

            // 4. Validate manifest
            $manifestErrors = $this->manifestValidator->validate($manifest);
            if (!empty($manifestErrors)) {
                throw new InvalidTemplateManifestException(
                    'Invalid template.json',
                    ['errors' => $manifestErrors]
                );
            }

            // 5. Validate structure
            $structureErrors = $this->structureValidator->validateStructure($tempDir, $manifest);
            if (!empty($structureErrors)) {
                throw new InvalidThemeStructureException(
                    'Invalid theme structure',
                    ['errors' => $structureErrors]
                );
            }

            // 6. Check conflicts
            $this->checkThemeConflict($manifest->name);

            // 7. Collect warnings
            $warnings = [];

            // Version compatibility
            $warnings = array_merge($warnings, $this->checkVersionCompatibility($manifest));

            // Missing assets
            $assetWarning = $this->structureValidator->checkAssets($tempDir, $manifest->name);
            if ($assetWarning !== null) {
                $warnings[] = $assetWarning;
            }

            // Security scan
            $securityWarnings = $this->runSecurityScan($tempDir, $manifest);
            $warnings = array_merge($warnings, $securityWarnings);

            // Translation validation
            $translationWarnings = $this->checkTranslations($tempDir, $manifest);
            $warnings = array_merge($warnings, $translationWarnings);

            // If warnings and not ignoring, return for confirmation
            if (!empty($warnings) && !$ignoreWarnings) {
                // Check for critical warnings
                $hasCritical = false;
                foreach ($warnings as $warning) {
                    if ($warning->severity === 'critical') {
                        $hasCritical = true;
                        break;
                    }
                }

                if ($hasCritical) {
                    throw new ThemeSecurityException(
                        'Critical security issues detected',
                        ['warnings' => $warnings]
                    );
                }

                return new ThemeUploadResultDTO(
                    success: false,
                    manifest: $manifest,
                    themePath: null,
                    assetsPath: null,
                    warnings: $warnings
                );
            }

            // 8. Move to final locations
            [$themePath, $assetsPath] = $this->moveToFinalLocations($tempDir, $manifest->name);

            // 9. Set permissions
            $this->setPermissions($themePath);
            if ($assetsPath !== null) {
                $this->setPermissions($assetsPath);
            }

            $this->logger->info('Theme uploaded successfully', [
                'theme' => $manifest->name,
                'version' => $manifest->version,
                'theme_path' => $themePath,
                'assets_path' => $assetsPath,
            ]);

            return new ThemeUploadResultDTO(
                success: true,
                manifest: $manifest,
                themePath: $themePath,
                assetsPath: $assetsPath,
                warnings: $warnings
            );

        } catch (Exception $e) {
            $this->logger->error('Theme upload failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);

            $this->rollback($tempDir, $themePath, $assetsPath);

            throw $e;
        }
    }

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

        // Verify it's actually a ZIP
        $zip = new ZipArchive();
        if ($zip->open($file->getPathname()) !== true) {
            throw new InvalidZipFileException('The file is not a valid ZIP archive');
        }
        $zip->close();
    }

    private function extractZipToTemp(UploadedFile $file): string
    {
        // Create unique temp directory
        $tempDir = $this->tempDirectory . '/theme-upload-' . uniqid();
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

    private function checkThemeConflict(string $themeName): void
    {
        $themePath = $this->themesDirectory . '/' . $themeName;
        $assetsPath = $this->publicAssetsDirectory . '/' . $themeName;

        if (is_dir($themePath) || is_dir($assetsPath)) {
            throw new ThemeAlreadyExistsException(
                "Theme '$themeName' already exists. Delete it first to upload a new version."
            );
        }
    }

    private function checkVersionCompatibility(TemplateManifestDTO $manifest): array
    {
        $warnings = [];

        // Check if theme targets older PteroCA version
        if (version_compare($manifest->pterocaVersion, $this->currentPterocaVersion, '<')) {
            $warnings[] = new ThemeUploadWarningDTO(
                type: 'outdated_pteroca_version',
                severity: 'warning',
                message: "Theme targets PteroCA v{$manifest->pterocaVersion}, current version is v{$this->currentPterocaVersion}. May not work correctly.",
                details: [
                    'theme_version' => $manifest->pterocaVersion,
                    'current_version' => $this->currentPterocaVersion,
                ]
            );
        }

        return $warnings;
    }

    private function runSecurityScan(string $tempDir, TemplateManifestDTO $manifest): array
    {
        $warnings = [];

        $themeRoot = "$tempDir/themes/{$manifest->name}";
        $warnings = array_merge($warnings, $this->securityValidator->scanTheme($themeRoot));

        $assetsRoot = "$tempDir/public/assets/theme/{$manifest->name}";
        if (is_dir($assetsRoot)) {
            $warnings = array_merge($warnings, $this->securityValidator->scanAssets($assetsRoot));
        }

        return $warnings;
    }

    private function checkTranslations(string $tempDir, TemplateManifestDTO $manifest): array
    {
        $warnings = [];

        if (empty($manifest->translations)) {
            return $warnings;
        }

        $translationsDir = "$tempDir/themes/{$manifest->name}/translations";

        foreach ($manifest->translations as $locale) {
            $translationFile = "$translationsDir/messages.$locale.yaml";
            if (!file_exists($translationFile)) {
                $warnings[] = new ThemeUploadWarningDTO(
                    type: 'missing_translation',
                    severity: 'warning',
                    message: "Translation declared but not found: messages.$locale.yaml",
                    details: ['locale' => $locale]
                );
            }
        }

        return $warnings;
    }

    private function moveToFinalLocations(string $tempDir, string $themeName): array
    {
        $sourceThemeDir = "$tempDir/themes/$themeName";
        $sourceAssetsDir = "$tempDir/public/assets/theme/$themeName";

        $targetThemeDir = $this->themesDirectory . '/' . $themeName;
        $targetAssetsDir = $this->publicAssetsDirectory . '/' . $themeName;

        // Move theme directory
        $this->filesystem->rename($sourceThemeDir, $targetThemeDir);

        // Move assets directory if exists
        $assetsPath = null;
        if (is_dir($sourceAssetsDir)) {
            $this->filesystem->rename($sourceAssetsDir, $targetAssetsDir);
            $assetsPath = $targetAssetsDir;
        }

        return [$targetThemeDir, $assetsPath];
    }

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

    private function rollback(?string $tempDir, ?string $themePath, ?string $assetsPath): void
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

        if ($themePath && is_dir($themePath)) {
            try {
                $this->filesystem->remove($themePath);
                $this->logger->debug('Rolled back theme directory', ['path' => $themePath]);
            } catch (Exception $e) {
                $this->logger->error('Failed to rollback theme directory', [
                    'path' => $themePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($assetsPath && is_dir($assetsPath)) {
            try {
                $this->filesystem->remove($assetsPath);
                $this->logger->debug('Rolled back assets directory', ['path' => $assetsPath]);
            } catch (Exception $e) {
                $this->logger->error('Failed to rollback assets directory', [
                    'path' => $assetsPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function isDangerousPath(string $path): bool
    {
        $dangerousPatterns = [
            '../',
            '..\\',
            "\0",
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

    private function isSymlink(array $stat): bool
    {
        // Check external_attr for symlink flag (Unix: 0120000)
        if (isset($stat['external_attr'])) {
            return ($stat['external_attr'] >> 16 & 0120000) === 0120000;
        }

        return false;
    }
}
