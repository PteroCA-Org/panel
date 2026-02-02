<?php

namespace App\Core\Service\Template;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

class ThemeCopyService
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly TranslatorInterface $translator,
        private readonly string $projectDir,
    ) {}

    public function validateThemeName(string $themeName): array
    {
        $errors = [];

        if (empty($themeName)) {
            $errors[] = $this->translator->trans('pteroca.crud.theme.validation.name_required');
            return $errors;
        }

        if (strlen($themeName) < 3) {
            $errors[] = $this->translator->trans('pteroca.crud.theme.validation.name_too_short');
        }

        if (strlen($themeName) > 50) {
            $errors[] = $this->translator->trans('pteroca.crud.theme.validation.name_too_long');
        }

        if (!preg_match('/^[a-z0-9\-_]+$/', $themeName)) {
            $errors[] = $this->translator->trans('pteroca.crud.theme.validation.name_invalid_chars');
        }

        if (preg_match('/^[\-_]|[\-_]$/', $themeName)) {
            $errors[] = $this->translator->trans('pteroca.crud.theme.validation.name_invalid_edges');
        }

        $reserved = ['default', 'system', 'admin', 'test'];
        if (in_array($themeName, $reserved, true)) {
            $errors[] = $this->translator->trans('pteroca.crud.theme.validation.name_reserved');
        }

        $themePath = $this->projectDir . '/themes/' . $themeName;
        if ($this->filesystem->exists($themePath)) {
            $errors[] = $this->translator->trans('pteroca.crud.theme.validation.name_already_exists');
        }

        return $errors;
    }

    public function copyTheme(string $sourceThemeName, string $newThemeName): void
    {
        $sourceThemePath = $this->projectDir . '/themes/' . $sourceThemeName;
        $targetThemePath = $this->projectDir . '/themes/' . $newThemeName;
        $sourceAssetsPath = $this->projectDir . '/public/assets/theme/' . $sourceThemeName;
        $targetAssetsPath = $this->projectDir . '/public/assets/theme/' . $newThemeName;

        try {
            $this->filesystem->mirror($sourceThemePath, $targetThemePath);

            if ($this->filesystem->exists($sourceAssetsPath)) {
                $this->filesystem->mirror($sourceAssetsPath, $targetAssetsPath);
            }

            $templateJsonPath = $targetThemePath . '/template.json';
            if ($this->filesystem->exists($templateJsonPath)) {
                $templateJson = json_decode(file_get_contents($templateJsonPath), true);
                $templateJson['template']['name'] = $newThemeName;
                file_put_contents($templateJsonPath, json_encode($templateJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } catch (\Exception $e) {
            if ($this->filesystem->exists($targetThemePath)) {
                $this->filesystem->remove($targetThemePath);
            }
            if ($this->filesystem->exists($targetAssetsPath)) {
                $this->filesystem->remove($targetAssetsPath);
            }
            throw $e;
        }
    }
}
