<?php

namespace App\Core\Service\Template;

use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use Symfony\Component\HttpFoundation\RequestStack;

class TemplateContextManager
{
    private const CONTEXT_PANEL = 'panel';
    private const CONTEXT_LANDING = 'landing';
    private const CONTEXT_EMAIL = 'email';

    public function __construct(
        private readonly SettingService $settingService,
        private readonly TemplateService $templateService,
        private readonly RequestStack $requestStack,
    ) {}

    public function getCurrentContext(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return self::CONTEXT_PANEL;
        }

        // Email context (set as request attribute when rendering emails)
        if ($request->attributes->get('_template_context') === self::CONTEXT_EMAIL) {
            return self::CONTEXT_EMAIL;
        }

        $route = $request->attributes->get('_route');

        // Landing context: routes starting with 'landing_' or 'homepage'
        if (str_starts_with($route, 'landing_') || $route === 'homepage') {
            return self::CONTEXT_LANDING;
        }

        // If no route matched (404), determine context by URL path
        if (!$route) {
            $pathInfo = $request->getPathInfo();

            // If path starts with /panel, use panel context
            if (str_starts_with($pathInfo, '/panel')) {
                return self::CONTEXT_PANEL;
            }

            // Otherwise, use landing context (public area is default)
            return self::CONTEXT_LANDING;
        }

        // Default: panel context
        return self::CONTEXT_PANEL;
    }

    public function getThemeForContext(string $context): string
    {
        return match($context) {
            self::CONTEXT_PANEL => $this->settingService->getSetting(SettingEnum::PANEL_THEME->value)
                ?? 'default',
            self::CONTEXT_LANDING => $this->settingService->getSetting(SettingEnum::LANDING_THEME->value)
                ?? $this->getFallbackTheme(self::CONTEXT_LANDING),
            self::CONTEXT_EMAIL => $this->settingService->getSetting(SettingEnum::EMAIL_THEME->value)
                ?? $this->getFallbackTheme(self::CONTEXT_EMAIL),
            default => 'default',
        };
    }

    private function getFallbackTheme(string $context): string
    {
        // If context theme not set, check if panel theme supports this context
        $panelTheme = $this->settingService->getSetting(SettingEnum::PANEL_THEME->value)
            ?? 'default';

        if ($this->templateService->themeSupportsContext($panelTheme, $context)) {
            return $panelTheme;
        }

        return 'default';
    }
}
