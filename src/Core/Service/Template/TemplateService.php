<?php

namespace App\Core\Service\Template;

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
