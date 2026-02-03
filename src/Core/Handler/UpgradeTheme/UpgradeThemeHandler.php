<?php

namespace App\Core\Handler\UpgradeTheme;

use App\Core\Handler\HandlerInterface;
use App\Core\Service\System\SystemVersionService;
use App\Core\Service\Template\TemplateService;
use App\Core\Service\Template\UpgradeThemeService;
use Exception;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class UpgradeThemeHandler implements HandlerInterface
{
    private string $themeName;
    private array $options = [];
    private ?string $backupPath = null;

    public function __construct(
        private readonly UpgradeThemeService $upgradeThemeService,
        private readonly TemplateService $templateService,
        private readonly SystemVersionService $systemVersionService,
        private readonly Filesystem $filesystem,
    ) {}

    public function setThemeName(string $themeName): void
    {
        $this->themeName = $themeName;
    }

    /**
     * Available options:
     * - no-backup: bool - Skip backup creation (not recommended)
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function handle(): void
    {
        $this->validate();

        $themePath = $this->templateService->getTemplatePath($this->themeName);

        try {
            if (!($this->options['no-backup'] ?? false)) {
                $this->backupPath = $this->upgradeThemeService->createBackup($this->themeName);
            }

            $this->upgradeThemeService->createLandingFolder($themePath);
            $this->upgradeThemeService->moveFilesToPanelContext($themePath);
            $this->upgradeThemeService->copyLandingFromDefault($themePath);
            $this->upgradeThemeService->copyThemeAssetsIfNeeded($this->themeName);

            $currentVersion = $this->systemVersionService->getCurrentVersion();
            $this->upgradeThemeService->updateTemplateJson($themePath, $currentVersion);

            $this->upgradeThemeService->validateUpgrade($themePath);

        } catch (Exception $e) {
            $this->rollback();

            throw new RuntimeException(
                sprintf('Upgrade failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    public function validate(): void
    {
        if (!$this->upgradeThemeService->isValidTheme($this->themeName)) {
            throw new RuntimeException(sprintf('Theme "%s" does not exist or is invalid', $this->themeName));
        }

        if (!$this->upgradeThemeService->needsUpgrade($this->themeName)) {
            throw new RuntimeException(sprintf('Theme "%s" is already upgraded to 0.6.3+ structure', $this->themeName));
        }
    }

    public function rollback(): void
    {
        if ($this->backupPath && $this->filesystem->exists($this->backupPath)) {
            try {
                $this->upgradeThemeService->restoreFromBackup($this->themeName, $this->backupPath);
            } catch (Exception $e) {
                // Log rollback failure but don't throw (original exception is more important)
                error_log(sprintf('Rollback failed: %s', $e->getMessage()));
            }
        }
    }

    public function getBackupPath(): ?string
    {
        return $this->backupPath;
    }
}
