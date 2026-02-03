<?php

namespace App\Core\Service\Template;

use App\Core\DTO\TemplateOptionsDTO;

class TemplateManager
{
    private array $currentTemplateMetadata = [];

    public function __construct(
        private readonly CurrentThemeService $currentThemeService,
        private readonly TemplateService $templateService,
    ) {}

    public function getCurrentTemplate(): string
    {
        return $this->currentThemeService->getCurrentTheme();
    }

    public function getCurrentTemplateVersion(): string
    {
        $this->loadCurrentTemplateInfo();

        return $this->currentTemplateMetadata['pterocaVersion'] ?? '0.0.0';
    }

    public function getCurrentTemplateOptions(): TemplateOptionsDTO
    {
        $this->loadCurrentTemplateInfo();

        return new TemplateOptionsDTO(
            $this->currentTemplateMetadata['options']['supportDarkMode'] ?? false,
            $this->currentTemplateMetadata['options']['supportCustomColors'] ?? false,
        );
    }

    public function isTemplateValid(string $template): bool
    {
        return !empty($this->templateService->getTemplateInfo($template));
    }

    private function loadCurrentTemplateInfo(): void
    {
        if (!empty($this->currentTemplateMetadata)) {
            return;
        }

        $currentTemplate = $this->getCurrentTemplate();
        $this->currentTemplateMetadata = $this->templateService->getRawTemplateInfo($currentTemplate);
    }
}
