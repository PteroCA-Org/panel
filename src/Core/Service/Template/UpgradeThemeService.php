<?php

namespace App\Core\Service\Template;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class UpgradeThemeService
{
    private const DEFAULT_THEME = 'default';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly TemplateService $templateService,
    ) {}

    public function needsUpgrade(string $themeName): bool
    {
        $metadata = $this->templateService->getRawTemplateInfo($themeName);

        if (isset($metadata['contexts'])) {
            return false;
        }

        return true;
    }

    public function isValidTheme(string $themeName): bool
    {
        $themePath = $this->templateService->getTemplatePath($themeName);

        if (!$this->filesystem->exists($themePath)) {
            return false;
        }

        $metadata = $this->templateService->getRawTemplateInfo($themeName);

        return !empty($metadata);
    }

    public function createBackup(string $themeName): string
    {
        $sourcePath = $this->templateService->getTemplatePath($themeName);
        $backupPath = $this->templateService->getTemplatePath($themeName . '_backup');

        if ($this->filesystem->exists($backupPath)) {
            $backupPath = $this->templateService->getTemplatePath($themeName . '_backup_' . date('Ymd_His'));
        }

        $this->filesystem->mirror($sourcePath, $backupPath);

        return $backupPath;
    }

    public function restoreFromBackup(string $themeName, string $backupPath): void
    {
        if (!$this->filesystem->exists($backupPath)) {
            throw new RuntimeException(sprintf('Backup path "%s" does not exist', $backupPath));
        }

        $themePath = $this->templateService->getTemplatePath($themeName);

        $this->filesystem->remove($themePath);
        $this->filesystem->mirror($backupPath, $themePath);
    }

    public function createLandingFolder(string $themePath): void
    {
        $landingPath = $themePath . '/landing';

        if ($this->filesystem->exists($landingPath)) {
            return;
        }

        $this->filesystem->mkdir($landingPath);
    }

    public function moveFilesToPanelContext(string $themePath): void
    {
        $panelPath = $themePath . '/panel';
        $filesToMove = $this->getFilesToMove();

        foreach ($filesToMove as $item) {
            $sourcePath = $themePath . '/' . $item;
            $targetPath = $panelPath . '/' . $item;

            if (!$this->filesystem->exists($sourcePath)) {
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!$this->filesystem->exists($targetDir)) {
                $this->filesystem->mkdir($targetDir);
            }

            $this->filesystem->rename($sourcePath, $targetPath);
        }
    }

    public function copyLandingFromDefault(string $themePath): void
    {
        $defaultLandingPath = $this->templateService->getTemplatePath(self::DEFAULT_THEME) . '/landing';
        $targetLandingPath = $themePath . '/landing';

        if (!$this->filesystem->exists($defaultLandingPath)) {
            throw new RuntimeException('Default theme landing folder not found');
        }

        if ($this->filesystem->exists($targetLandingPath)) {
            $this->filesystem->remove($targetLandingPath);
        }

        $this->filesystem->mirror($defaultLandingPath, $targetLandingPath);
    }

    public function updateTemplateJson(string $themePath, string $currentVersion): void
    {
        $jsonPath = $themePath . '/template.json';

        if (!$this->filesystem->exists($jsonPath)) {
            throw new RuntimeException('template.json not found');
        }

        $content = file_get_contents($jsonPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('template.json is malformed: ' . json_last_error_msg());
        }

        $data['template']['contexts'] = ['panel', 'landing', 'email'];

        if (!isset($data['template']['translations'])) {
            $data['template']['translations'] = [];
        }

        $data['template']['pterocaVersion'] = $currentVersion;

        $this->filesystem->dumpFile(
            $jsonPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function copyThemeAssetsIfNeeded(string $themeName): void
    {
        $assetsPath = $this->templateService->getTemplateAssetsPath($themeName);

        if ($this->filesystem->exists($assetsPath)) {
            return;
        }

        $defaultAssetsPath = $this->templateService->getTemplateAssetsPath(self::DEFAULT_THEME);

        if (!$this->filesystem->exists($defaultAssetsPath)) {
            throw new RuntimeException('Default theme assets not found');
        }

        $this->filesystem->mirror($defaultAssetsPath, $assetsPath);
    }

    public function validateUpgrade(string $themePath): void
    {
        $errors = [];

        if (!$this->filesystem->exists($themePath . '/landing')) {
            $errors[] = 'Landing folder was not created';
        }

        if (!$this->filesystem->exists($themePath . '/panel')) {
            $errors[] = 'Panel folder does not exist';
        }

        $requiredPanelFiles = ['bundles', 'components'];
        foreach ($requiredPanelFiles as $file) {
            if (!$this->filesystem->exists($themePath . '/panel/' . $file)) {
                $errors[] = sprintf('Required file/folder "%s" not found in panel context', $file);
            }
        }

        $metadata = $this->templateService->getRawTemplateInfo(basename($themePath));
        if (!isset($metadata['contexts'])) {
            $errors[] = 'template.json was not updated with contexts field';
        }

        if (!empty($errors)) {
            throw new RuntimeException('Upgrade validation failed: ' . implode(', ', $errors));
        }
    }

    private function getFilesToMove(): array
    {
        return [
            '_partials',
            'bundles',
            'components',
            'form',
            'sso',
            'base.html.twig',
        ];
    }

    private function shouldSkipFile(string $file): bool
    {
        $skipFiles = [
            'template.json',
            'email',
            'panel',
            'landing',
            '.git',
            '.gitignore',
        ];

        return in_array($file, $skipFiles, true);
    }
}
