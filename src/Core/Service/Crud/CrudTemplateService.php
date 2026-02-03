<?php

namespace App\Core\Service\Crud;

use App\Core\Enum\OverwriteableCrudTemplatesEnum;
use App\Core\Service\Template\CurrentThemeService;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;

class CrudTemplateService
{
    private const TEMPLATES_CACHE_KEY = 'templates_to_override';

    private const DEFAULT_TEMPLATE = 'default';

    public function __construct(
        private readonly CurrentThemeService $currentThemeService,
        private readonly CacheInterface $cache,
        private readonly Filesystem $fileSystem,
        private readonly string $projectDirectory,
        private readonly string $environment,
    )
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getTemplatesToOverride(array $templateContext): array
    {
        $templateContext = $this->prepareTemplateContext($templateContext);
        $currentTemplate = $this->currentThemeService->getCurrentTheme();

        // In dev environment, skip cache to see template changes immediately
        if ($this->environment === 'dev') {
            return $this->resolveTemplates($currentTemplate, $templateContext);
        }

        // In prod environment, use cache for performance
        return $this->cache->get(
            $this->createTemplateCacheKey($currentTemplate, $templateContext),
            fn () => $this->resolveTemplates($currentTemplate, $templateContext)
        );
    }

    /**
     * Resolve templates without caching.
     */
    private function resolveTemplates(string $currentTemplate, string $templateContext): array
    {
        $templatesToOverride = [];

        foreach (OverwriteableCrudTemplatesEnum::toArray() as $template) {
            $resolvedTemplate = $this->findTemplateHierarchically(
                $template,
                $currentTemplate,
                $templateContext
            );

            if ($resolvedTemplate !== null) {
                $templatesToOverride[$template] = $resolvedTemplate;
            }
        }

        return $templatesToOverride;
    }

    protected function prepareTemplateContext(array $templateContext): string
    {
        $templateContext = implode('/', $templateContext);
        return str_replace(' ', '_', strtolower($templateContext));
    }

    private function createTemplateCacheKey(string $currentTemplate, string $templateContext): string
    {
        $cacheKey = sprintf('%s_%s_%s', self::TEMPLATES_CACHE_KEY,$currentTemplate, $templateContext);

        return str_replace(['/', '\\'], '_', $cacheKey);
    }

    private function getCrudTemplateName(string $template): string
    {
        $templateName = explode('/', $template);

        return end($templateName);
    }

    /**
     * Find template using hierarchical fallback strategy.
     *
     * For a template context like 'setting/panel_theme', it will search:
     * 1. themes/{theme}/panel/crud/setting/panel_theme/{template}.html.twig
     * 2. themes/{theme}/panel/crud/setting/{template}.html.twig
     * 3. If theme != default, repeat 1-2 for 'default' theme
     * 4. Returns null if not found (EasyAdmin default will be used)
     */
    private function findTemplateHierarchically(
        string $template,
        string $currentTheme,
        string $templateContext
    ): ?string {
        $templateName = $this->getCrudTemplateName($template);

        // Build hierarchy of contexts to check
        $contexts = $this->buildContextHierarchy($templateContext);

        // Try current theme first
        foreach ($contexts as $context) {
            $templatePath = $this->getCrudTemplatePath($templateName, $currentTheme, $context);

            if ($this->fileSystem->exists($templatePath)) {
                return $this->getTemplateReference($context, $templateName);
            }
        }

        // Fallback to default theme if different
        if ($currentTheme !== self::DEFAULT_TEMPLATE) {
            foreach ($contexts as $context) {
                $templatePath = $this->getCrudTemplatePath($templateName, self::DEFAULT_TEMPLATE, $context);

                if ($this->fileSystem->exists($templatePath)) {
                    return $this->getTemplateReference($context, $templateName);
                }
            }
        }

        return null;
    }

    /**
     * Build hierarchical context array.
     *
     * Example: 'setting/panel_theme' becomes:
     * - ['setting/panel_theme', 'setting']
     *
     * Example: 'product' becomes:
     * - ['product']
     */
    private function buildContextHierarchy(string $templateContext): array
    {
        if (empty($templateContext)) {
            return [''];
        }

        $parts = explode('/', $templateContext);
        $hierarchy = [];

        // Start with most specific (full path)
        $hierarchy[] = $templateContext;

        // Remove last segment for each level up
        while (count($parts) > 1) {
            array_pop($parts);
            $hierarchy[] = implode('/', $parts);
        }

        return $hierarchy;
    }

    /**
     * Get the template reference path for Twig.
     */
    private function getTemplateReference(string $context, string $templateName): string
    {
        $contextPath = !empty($context) ? $context . '/' : '';
        return sprintf('panel/crud/%s%s.html.twig', $contextPath, $templateName);
    }

    /**
     * Get full filesystem path to template.
     */
    private function getCrudTemplatePath(string $templateName, string $theme, string $context): string
    {
        $contextPath = !empty($context) ? $context . '/' : '';
        return sprintf(
            '%s/themes/%s/panel/crud/%s%s.html.twig',
            $this->projectDirectory,
            $theme,
            $contextPath,
            $templateName
        );
    }
}