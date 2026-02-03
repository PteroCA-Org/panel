<?php

namespace App\Core\Service\Template;

use App\Core\DTO\ThemeDTO;
use App\Core\Service\System\SystemVersionService;
use DirectoryIterator;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TemplateService
{
    public const METADATA_FILE = 'template.json';

    private const TEMPLATES_DIRECTORY = 'themes';

    public function __construct(
        private readonly SystemVersionService $systemVersionService,
        private readonly KernelInterface $kernel,
        private readonly TranslatorInterface $translator,
    )
    {
    }

    public function getAvailableTemplates(): array
    {
        $templates = [];

        $templatesDirectoryPath = $this->getTemplatePath();
        $directory = new DirectoryIterator($templatesDirectoryPath);
        foreach ($directory as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                $templateMetadata = $this->loadTemplateMetadata($fileInfo->getPathname());
                if (empty($templateMetadata)) {
                    continue;
                }

                $templateName = current($templateMetadata);
                $templates[$templateName] = $templateName;
            }
        }

        return $templates;
    }

    public function getTemplateInfo(string $templateName): array
    {
        $templateInfo = $this->loadTemplateMetadata($this->getTemplatePath($templateName));

        $currentPterocaVersion = $this->systemVersionService->getCurrentVersion();
        $pterocaVersionIndex = $this->translator->trans('pteroca.crud.setting.template.pterocaVersion');
        $templatePterocaVersion = $templateInfo[$pterocaVersionIndex] ?? null;
        $isOutdated = !empty($templatePterocaVersion)
            && version_compare($templatePterocaVersion, $currentPterocaVersion, '<');

        if ($isOutdated) {
            $templateInfo[$pterocaVersionIndex] = sprintf(
                '%s (%s %s)',
                $templatePterocaVersion,
                '<i class="fas fa-exclamation-triangle text-warning"></i>',
                $this->translator->trans('pteroca.crud.setting.template.outdated')
            );
        }

        // Validate theme translations if declared
        $translationErrors = $this->validateThemeTranslations($templateName);
        if (!empty($translationErrors)) {
            $translationsLabel = $this->translator->trans('pteroca.crud.setting.template.translations');
            $templateInfo[$translationsLabel] = sprintf(
                '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> %s</span><ul class="mb-0 mt-1"><li>%s</li></ul>',
                $this->translator->trans('pteroca.crud.setting.template.translation_validation_errors'),
                implode('</li><li>', array_map('htmlspecialchars', $translationErrors))
            );
        } else {
            // Show available translation files if no errors
            $availableTranslations = $this->getThemeTranslationFiles($templateName);
            if (!empty($availableTranslations)) {
                $translationsLabel = $this->translator->trans('pteroca.crud.setting.template.translations');
                $templateInfo[$translationsLabel] = implode(', ', $availableTranslations);
            }
        }

        return $templateInfo;
    }

    public function getRawTemplateInfo(string $templateName): array
    {
        return $this->loadTemplateMetadata($this->getTemplatePath($templateName), true);
    }

    public function getTemplatePath(?string $templateName = null): string
    {
        $templatePath = $this->kernel->getProjectDir() . DIRECTORY_SEPARATOR . self::TEMPLATES_DIRECTORY;
        if ($templateName !== null) {
            $templatePath .= DIRECTORY_SEPARATOR . $templateName;
        }

        return $templatePath;
    }

    public function getTemplateAssetsPath(?string $templateName): string
    {
        $assetsPath = $this->kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'theme';
        if ($templateName !== null) {
            $assetsPath .= DIRECTORY_SEPARATOR . $templateName;
        }

        return $assetsPath;
    }

    public function themeSupportsContext(string $themeName, string $context): bool
    {
        $metadata = $this->getRawTemplateInfo($themeName);

        // DEPRECATED: Backward compatibility for legacy themes (introduced in v0.6.3)
        // This fallback will be REMOVED in a future version (v0.8.0+)
        // Legacy themes without 'contexts' field are assumed to support only "panel" context
        // ACTION REQUIRED: Update your template.json to include "contexts": ["panel", "landing", "email"]
        if (!isset($metadata['contexts'])) {
            return $context === 'panel';
        }

        return in_array($context, $metadata['contexts'], true);
    }

    public function getAvailableTemplatesForContext(string $context): array
    {
        $allTemplates = $this->getAvailableTemplates();
        $contextTemplates = [];

        foreach ($allTemplates as $templateName) {
            if ($this->themeSupportsContext($templateName, $context)) {
                $contextTemplates[$templateName] = $templateName;
            }
        }

        return $contextTemplates;
    }

    /**
     * Validate theme translations configuration
     *
     * @return array Array of validation errors (empty if valid)
     */
    public function validateThemeTranslations(string $themeName): array
    {
        $errors = [];
        $themeInfo = $this->getRawTemplateInfo($themeName);
        $declaredTranslations = $themeInfo['translations'] ?? [];

        // If no translations declared, no validation needed
        if (empty($declaredTranslations)) {
            return $errors;
        }

        // Check if translations is an array
        if (!is_array($declaredTranslations)) {
            $errors[] = 'Theme translations field must be an array';
            return $errors;
        }

        $translationDir = $this->getTemplatePath($themeName) . DIRECTORY_SEPARATOR . 'translations';

        // Check if translations directory exists
        if (!is_dir($translationDir)) {
            $errors[] = sprintf(
                'Theme declares translations %s but directory "%s" not found',
                json_encode($declaredTranslations),
                'translations/'
            );
            return $errors;
        }

        // Validate each declared locale has corresponding translation file
        foreach ($declaredTranslations as $locale) {
            $messagesFile = $translationDir . DIRECTORY_SEPARATOR . 'messages.' . $locale . '.yaml';
            if (!file_exists($messagesFile)) {
                $errors[] = sprintf(
                    'Missing translation file: messages.%s.yaml (declared in template.json)',
                    $locale
                );
            }
        }

        return $errors;
    }

    /**
     * Get list of available translation files for a theme
     *
     * @return array Array of locale codes (e.g., ['en', 'pl', 'de'])
     */
    public function getThemeTranslationFiles(string $themeName): array
    {
        $translationDir = $this->getTemplatePath($themeName) . DIRECTORY_SEPARATOR . 'translations';

        if (!is_dir($translationDir)) {
            return [];
        }

        $locales = [];
        $directory = new DirectoryIterator($translationDir);

        foreach ($directory as $fileInfo) {
            if ($fileInfo->isFile() && preg_match('/^messages\.([a-z]{2}(?:_[A-Z]{2})?)\.yaml$/', $fileInfo->getFilename(), $matches)) {
                $locales[] = $matches[1];
            }
        }

        return $locales;
    }

    /**
     * Get all themes for a specific context as DTOs
     */
    public function getThemesForContext(string $context, ?string $activeThemeName = null): array
    {
        $allThemes = $this->getAvailableTemplates();
        $themes = [];

        foreach ($allThemes as $themeName) {
            if ($this->themeSupportsContext($themeName, $context)) {
                $themes[] = $this->createThemeDTO($themeName, $context, $themeName === $activeThemeName);
            }
        }

        return $themes;
    }

    /**
     * Get all themes with multi-context active status
     */
    public function getAllThemesWithActiveContexts(
        string $panelTheme,
        string $landingTheme,
        string $emailTheme
    ): array {
        $allThemes = $this->getAvailableTemplates();
        $themes = [];

        foreach ($allThemes as $themeName) {
            $activeContexts = [];

            if ($themeName === $panelTheme && $this->themeSupportsContext($themeName, 'panel')) {
                $activeContexts[] = 'panel';
            }
            if ($themeName === $landingTheme && $this->themeSupportsContext($themeName, 'landing')) {
                $activeContexts[] = 'landing';
            }
            if ($themeName === $emailTheme && $this->themeSupportsContext($themeName, 'email')) {
                $activeContexts[] = 'email';
            }

            $metadata = $this->getRawTemplateInfo($themeName);

            $themes[] = new ThemeDTO(
                name: $themeName,
                displayName: $metadata['name'] ?? $themeName,
                version: $metadata['version'] ?? 'unknown',
                author: $metadata['author'] ?? 'Unknown',
                description: $metadata['description'] ?? '',
                license: $metadata['license'] ?? 'Unknown',
                contexts: $metadata['contexts'] ?? [],
                translations: $metadata['translations'] ?? [],
                options: $metadata['options'] ?? [],
                pterocaVersion: $metadata['pterocaVersion'] ?? 'unknown',
                phpVersion: $metadata['phpVersion'] ?? 'unknown',
                isActive: !empty($activeContexts),
                context: null,
                activeContexts: $activeContexts,
            );
        }

        return $themes;
    }

    /**
     * Get single theme as DTO
     */
    public function getThemeDTO(string $themeName, string $context, bool $isActive): ThemeDTO
    {
        return $this->createThemeDTO($themeName, $context, $isActive);
    }

    /**
     * Create a ThemeDTO from theme metadata
     */
    private function createThemeDTO(string $themeName, string $context, bool $isActive): ThemeDTO
    {
        $metadata = $this->getRawTemplateInfo($themeName);

        return new ThemeDTO(
            name: $metadata['name'] ?? $themeName,
            displayName: $metadata['name'] ?? $themeName,
            version: $metadata['version'] ?? 'unknown',
            author: $metadata['author'] ?? 'Unknown',
            description: $metadata['description'] ?? '',
            license: $metadata['license'] ?? 'Unknown',
            contexts: $metadata['contexts'] ?? [],
            translations: $metadata['translations'] ?? [],
            options: $metadata['options'] ?? [],
            pterocaVersion: $metadata['pterocaVersion'] ?? 'unknown',
            phpVersion: $metadata['phpVersion'] ?? 'unknown',
            isActive: $isActive,
            context: $context,
        );
    }

    private function loadTemplateMetadata(string $templatePath, bool $loadRawData = false): array
    {
        $metadataPath = $templatePath . DIRECTORY_SEPARATOR . self::METADATA_FILE;
        if (!file_exists($metadataPath)) {
            return [];
        }

        $metaData = json_decode(file_get_contents($metadataPath), true);
        if (empty($metaData['template'])) {
            return [];
        }

        return $this->prepareTemplateMetadata($metaData['template'], $loadRawData);
    }

    private function prepareTemplateMetadata(array $templateMetadata, bool $loadRawData = false): array
    {
        $preparedMetaData = [];
        foreach ($templateMetadata as $key => $value) {
            $label = !$loadRawData
                ? $this->translator->trans(sprintf('pteroca.crud.setting.template.%s', $key))
                : $key;

            if (is_array($value)) {
                $value = $this->prepareTemplateMetadata($value, $loadRawData);
            }

            if ($label !== '' && $label !== null) {
                $preparedMetaData[$label] = $value;
            }
        }

        return $preparedMetaData;
    }
}
