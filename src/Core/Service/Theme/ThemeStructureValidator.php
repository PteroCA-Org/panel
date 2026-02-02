<?php

namespace App\Core\Service\Theme;

use App\Core\DTO\TemplateManifestDTO;
use App\Core\DTO\ThemeUploadWarningDTO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ThemeStructureValidator
{
    public function findThemeRoot(string $tempDir): ?string
    {
        $themesDir = $tempDir . '/themes';

        if (!is_dir($themesDir)) {
            return null;
        }

        $dirs = glob($themesDir . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            if (file_exists($dir . '/template.json')) {
                return $dir;
            }
        }

        return null;
    }

    public function validateStructure(string $tempDir, TemplateManifestDTO $manifest): array
    {
        $errors = [];

        $themeName = $manifest->name;
        $themeDir = "$tempDir/themes/$themeName";

        // Check theme directory exists
        if (!is_dir($themeDir)) {
            $errors[] = "Theme directory themes/$themeName/ not found in ZIP";
        }

        // Check template.json exists
        if (!file_exists("$themeDir/template.json")) {
            $errors[] = 'template.json not found in theme directory';
        }

        // Check context directories
        foreach ($manifest->contexts as $context) {
            if (!is_dir("$themeDir/$context")) {
                $errors[] = "Context '$context' declared but directory themes/$themeName/$context/ not found";
            }
        }

        // Check for files outside allowed paths
        $allowedPaths = [
            "themes/$themeName/",
            "public/assets/theme/$themeName/",
        ];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relativePath = str_replace($tempDir . '/', '', $file->getPathname());
                    $isAllowed = false;

                    foreach ($allowedPaths as $path) {
                        if (str_starts_with($relativePath, $path)) {
                            $isAllowed = true;
                            break;
                        }
                    }

                    if (!$isAllowed) {
                        $errors[] = "File outside allowed paths: $relativePath";
                    }
                }
            }
        } catch (\Exception $e) {
            $errors[] = "Failed to validate directory structure: " . $e->getMessage();
        }

        return $errors;
    }

    public function checkAssets(string $tempDir, string $themeName): ?ThemeUploadWarningDTO
    {
        $assetsDir = "$tempDir/public/assets/theme/$themeName";

        if (!is_dir($assetsDir)) {
            return new ThemeUploadWarningDTO(
                type: 'missing_assets',
                severity: 'warning',
                message: 'No assets directory found in ZIP. Theme may not display correctly.',
                details: ['expected_path' => "public/assets/theme/$themeName/"]
            );
        }

        return null;
    }
}
