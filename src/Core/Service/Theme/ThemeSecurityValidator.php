<?php

namespace App\Core\Service\Theme;

use App\Core\DTO\ThemeUploadWarningDTO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ThemeSecurityValidator
{
    private const ALLOWED_ASSET_EXTENSIONS = [
        'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'otf',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
    ];

    private const DANGEROUS_EXTENSIONS = [
        'php', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com',
    ];

    public function scanTheme(string $themeRoot): array
    {
        $warnings = [];

        // Scan Twig templates
        $twigFiles = $this->findTwigTemplates($themeRoot);
        foreach ($twigFiles as $file) {
            $warnings = array_merge($warnings, $this->scanTwigFile($file));
        }

        // Check for dangerous files
        $warnings = array_merge($warnings, $this->checkDangerousFiles($themeRoot));

        return $warnings;
    }

    public function scanAssets(string $assetsRoot): array
    {
        $warnings = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($assetsRoot, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());

                // Check for dangerous files
                if (in_array($extension, self::DANGEROUS_EXTENSIONS)) {
                    $warnings[] = new ThemeUploadWarningDTO(
                        type: 'dangerous_file',
                        severity: 'critical',
                        message: "Executable file found in assets: {$file->getFilename()}",
                        details: ['file' => $file->getPathname()]
                    );
                }

                // Check for disallowed asset types
                if (!in_array($extension, self::ALLOWED_ASSET_EXTENSIONS)) {
                    $warnings[] = new ThemeUploadWarningDTO(
                        type: 'invalid_asset_type',
                        severity: 'warning',
                        message: "Unusual file type in assets: {$file->getFilename()}",
                        details: ['file' => $file->getPathname(), 'extension' => $extension]
                    );
                }
            }
        } catch (\Exception $e) {
            $warnings[] = new ThemeUploadWarningDTO(
                type: 'scan_error',
                severity: 'warning',
                message: 'Failed to scan assets directory: ' . $e->getMessage(),
                details: []
            );
        }

        return $warnings;
    }

    private function findTwigTemplates(string $themeRoot): array
    {
        $twigFiles = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($themeRoot, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'twig') {
                    $twigFiles[] = $file->getPathname();
                }
            }
        } catch (\Exception $e) {
            // Silently skip if directory iteration fails
        }

        return $twigFiles;
    }

    private function scanTwigFile(string $filePath): array
    {
        $warnings = [];
        $content = file_get_contents($filePath);

        if ($content === false) {
            return $warnings;
        }

        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // Check for |raw filter (warning)
            if (preg_match('/\|\s*raw\b/', $line)) {
                $warnings[] = new ThemeUploadWarningDTO(
                    type: 'twig_raw_filter',
                    severity: 'warning',
                    message: "Raw filter found in template (bypasses XSS protection)",
                    details: [
                        'file' => basename($filePath),
                        'line' => $lineNum + 1,
                        'code' => trim($line),
                    ]
                );
            }

            // Check for dynamic includes (warning)
            if (preg_match('/{%\s*include\s+(?!["\']).+%}/', $line)) {
                $warnings[] = new ThemeUploadWarningDTO(
                    type: 'twig_dynamic_include',
                    severity: 'warning',
                    message: "Dynamic include with variable path detected",
                    details: [
                        'file' => basename($filePath),
                        'line' => $lineNum + 1,
                        'code' => trim($line),
                    ]
                );
            }
        }

        return $warnings;
    }

    private function checkDangerousFiles(string $themeRoot): array
    {
        $warnings = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($themeRoot, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());

                if (in_array($extension, self::DANGEROUS_EXTENSIONS)) {
                    $warnings[] = new ThemeUploadWarningDTO(
                        type: 'dangerous_file',
                        severity: 'critical',
                        message: "Executable file found in theme: {$file->getFilename()}",
                        details: ['file' => $file->getPathname()]
                    );
                }
            }
        } catch (\Exception $e) {
            $warnings[] = new ThemeUploadWarningDTO(
                type: 'scan_error',
                severity: 'warning',
                message: 'Failed to scan theme directory: ' . $e->getMessage(),
                details: []
            );
        }

        return $warnings;
    }
}
