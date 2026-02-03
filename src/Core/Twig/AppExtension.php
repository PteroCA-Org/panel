<?php

namespace App\Core\Twig;

use Exception;
use Twig\TwigFilter;
use Twig\TwigFunction;
use App\Core\Enum\SettingEnum;
use App\Core\DTO\TemplateOptionsDTO;
use App\Core\Service\SettingService;
use App\Core\Trait\FormatBytesTrait;
use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use App\Core\Enum\EmailVerificationValueEnum;
use App\Core\Service\Template\TemplateManager;
use App\Core\Service\Template\CurrentThemeService;
use Symfony\Component\Routing\RouterInterface;
use App\Core\Service\System\SystemVersionService;
use App\Core\Service\Plugin\PluginAssetManager;
use App\Core\Service\Pterodactyl\PterodactylRedirectService;

class AppExtension extends AbstractExtension
{
    use FormatBytesTrait;

    public function __construct(
        private readonly SystemVersionService $systemVersionService,
        private readonly SettingService $settingService,
        private readonly TemplateManager $templateManager,
        private readonly CurrentThemeService $currentThemeService,
        private readonly Packages $packages,
        private readonly RouterInterface $router,
        private readonly PterodactylRedirectService $pterodactylRedirectService,
        private readonly PluginAssetManager $pluginAssetManager,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_currency', [$this, 'getCurrency']),
            new TwigFunction('get_default_theme_colors', [$this, 'getDefaultThemeColors']),
            new TwigFunction('get_app_version', [$this, 'getAppVersion']),
            new TwigFunction('get_logo', [$this, 'getLogo']),
            new TwigFunction('get_title', [$this, 'getTitle']),
            new TwigFunction('get_site_url', [$this, 'getSiteUrl']),
            new TwigFunction('show_email_verification_alert', [$this, 'showEmailVerificationAlert']),
            new TwigFunction('get_captcha_site_key', [$this, 'getCaptchaSiteKey']),
            new TwigFunction('get_favicon', [$this, 'getFavicon']),
            new TwigFunction('use_pterodactyl_panel_as_client_panel', [$this, 'usePterodactylPanelAsClientPanel']),
            new TwigFunction('get_pterodactyl_panel_url', [$this, 'getPterodactylPanelUrl']),
            new TwigFunction('is_pterodactyl_sso_enabled', [$this, 'isPterodactylSSOEnabled']),
            new TwigFunction('template_asset', [$this, 'templateAsset']),
            new TwigFunction('get_current_template_options', [$this, 'getCurrentTemplateOptions']),
            new TwigFunction('plugin_asset', [$this, 'pluginAsset']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_bytes', [$this, 'formatBytes']),
        ];
    }

    /**
     * @throws Exception
     */
    public function getCurrency(): string
    {
        $currency = $this->settingService->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value);
        if (empty($currency)) {
            throw new Exception('Internal currency is not set');
        }
        return $currency;
    }

    public function getDefaultThemeColors(): array
    {
        $themeSettings = [
            SettingEnum::DEFAULT_THEME_LIGHT_MODE_COLOR->value,
            SettingEnum::DEFAULT_THEME_DARK_MODE_COLOR->value,
        ];

        $settings = [];
        foreach ($themeSettings as $setting) {
            $settings[$setting] = $this->settingService->getSetting($setting);
        }

        return $settings;
    }

    public function getLogo(): string
    {
        $uploadedLogo = $this->settingService->getSetting(SettingEnum::LOGO->value);
        if (!empty($uploadedLogo)) {
            return sprintf('/uploads/settings/%s', $uploadedLogo);
        }
        return '/assets/img/logo/logo.png';
    }

    public function getFavicon(): string
    {
        $uploadedFavicon = $this->settingService->getSetting(SettingEnum::SITE_FAVICON->value);
        if (!empty($uploadedFavicon)) {
            return sprintf('/uploads/settings/%s', $uploadedFavicon);
        }
        return '/assets/img/favicon/favicon.ico';
    }

    public function getTitle(): string
    {
        return $this->settingService->getSetting(SettingEnum::SITE_TITLE->value) ?? '';
    }

    public function getSiteUrl(): string
    {
        return $this->settingService->getSetting(SettingEnum::SITE_URL->value) ?? '';
    }

    public function showEmailVerificationAlert(): bool
    {
        $emailVerificationValue = $this->settingService
            ->getSetting(SettingEnum::REQUIRE_EMAIL_VERIFICATION->value);

        return in_array($emailVerificationValue, [
            EmailVerificationValueEnum::REQUIRED->value,
            EmailVerificationValueEnum::OPTIONAL->value,
        ]);
    }

    public function usePterodactylPanelAsClientPanel(): bool
    {
        return $this->pterodactylRedirectService->shouldUsePterodactylPanel();
    }

    public function isPterodactylSSOEnabled(): bool
    {
        return $this->pterodactylRedirectService->isSSOEnabled();
    }

    public function getPterodactylPanelUrl(string $path = ''): string
    {
        return $this->pterodactylRedirectService->getPterodactylUrl($path);
    }

    public function getCaptchaSiteKey(): ?string
    {
        $isCaptchaEnabled = $this->settingService->getSetting(SettingEnum::GOOGLE_CAPTCHA_VERIFICATION->value);
        if (empty($isCaptchaEnabled)) {
            return null;
        }
        return $this->settingService->getSetting(SettingEnum::GOOGLE_CAPTCHA_SITE_KEY->value);
    }

    public function getAppVersion(): string
    {
        return $this->systemVersionService->getCurrentVersion();
    }

    public function templateAsset(string $path): string
    {
        $currentTheme = $this->currentThemeService->getCurrentTheme();
        $path = sprintf('/assets/theme/%s/%s', $currentTheme, $path);

        return $this->packages->getUrl($path);
    }

    public function getCurrentTemplateOptions(): TemplateOptionsDTO
    {
        return $this->templateManager->getCurrentTemplateOptions();
    }

    public function pluginAsset(string $pluginName, string $path): string
    {
        return $this->pluginAssetManager->getAssetUrl($pluginName, $path);
    }
}
