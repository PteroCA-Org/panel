<?php

namespace App\Core\Service\Theme;

use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use App\Core\Service\System\CacheService;
use App\Core\Service\Template\TemplateService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class ThemeDeleteService
{
    public function __construct(
        private readonly string $themesDirectory,
        private readonly string $publicAssetsDirectory,
        private readonly TemplateService $templateService,
        private readonly SettingService $settingService,
        private readonly ThemeRecordManager $themeRecordManager,
        private readonly CacheService $cacheService,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ThemeDeleteException
     */
    public function deleteTheme(string $themeName): string
    {
        $this->validate($themeName);

        $displayName = $this->getDisplayName($themeName);

        $this->removeFiles($themeName);
        $this->themeRecordManager->remove($themeName);
        $this->cacheService->clearCacheOnShutdown();

        $this->logger->info('Theme deleted successfully', [
            'theme' => $themeName,
        ]);

        return $displayName;
    }

    private function validate(string $themeName): void
    {
        if ($themeName === TemplateService::DEFAULT_THEME) {
            throw new \InvalidArgumentException('Cannot delete the default system theme.');
        }

        if (!$this->templateService->themeExists($themeName)) {
            throw new \InvalidArgumentException(sprintf('Theme "%s" not found.', $themeName));
        }

        $contexts = [
            'panel' => SettingEnum::PANEL_THEME->value,
            'landing' => SettingEnum::LANDING_THEME->value,
            'email' => SettingEnum::EMAIL_THEME->value,
        ];

        foreach ($contexts as $contextName => $settingName) {
            if ($this->settingService->getSetting($settingName) === $themeName) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot delete theme that is active in "%s" context.',
                    $contextName
                ));
            }
        }
    }

    private function getDisplayName(string $themeName): string
    {
        $metadata = $this->templateService->getRawTemplateInfo($themeName);
        return $metadata['name'] ?? $themeName;
    }

    private function removeFiles(string $themeName): void
    {
        $themePath = $this->themesDirectory . '/' . $themeName;
        $assetsPath = $this->publicAssetsDirectory . '/' . $themeName;

        if (is_dir($themePath)) {
            $this->filesystem->remove($themePath);
        }

        if (is_dir($assetsPath)) {
            $this->filesystem->remove($assetsPath);
        }
    }
}
