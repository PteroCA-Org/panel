<?php

namespace App\Core\Service\Pterodactyl;

use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;

class PterodactylRedirectService
{
    public function __construct(
        private readonly SettingService $settingsService,
        private readonly RouterInterface $router,
    ) {}

    /**
     * Check if Pterodactyl Panel should be used as client panel
     */
    public function shouldUsePterodactylPanel(): bool
    {
        return !empty($this->settingsService->getSetting(
            SettingEnum::PTERODACTYL_PANEL_USE_AS_CLIENT_PANEL->value
        ));
    }

    /**
     * Check if SSO is enabled
     */
    public function isSSOEnabled(): bool
    {
        return !empty($this->settingsService->getSetting(
            SettingEnum::PTERODACTYL_SSO_ENABLED->value
        ));
    }

    /**
     * Get redirect URL for a server (without redirecting)
     *
     * @param string $serverIdentifier Pterodactyl server identifier
     * @return string The complete URL
     */
    public function getServerUrl(string $serverIdentifier): string
    {
        return $this->getPterodactylUrl('/server/' . $serverIdentifier);
    }

    /**
     * Get redirect URL for any Pterodactyl path
     *
     * @param string $path Path within Pterodactyl (e.g., '/server/abc123')
     * @return string The complete URL
     */
    public function getPterodactylUrl(string $path = ''): string
    {
        if ($this->isSSOEnabled()) {
            $url = $this->router->generate('sso_redirect');

            if (!empty($path)) {
                $url .= '?redirect_path=' . ltrim($path, '/');
            }

            return $url;
        }

        $pterodactylUrl = $this->settingsService->getSetting(
            SettingEnum::PTERODACTYL_PANEL_URL->value
        );

        if (!empty($path)) {
            $pterodactylUrl = rtrim($pterodactylUrl, '/') . '/' . ltrim($path, '/');
        }

        return $pterodactylUrl;
    }

    /**
     * Get base Pterodactyl panel URL (for emails, etc.)
     * Considers USE_AS_CLIENT_PANEL setting
     *
     * @return string Base URL for client panel
     */
    public function getBasePanelUrl(): string
    {
        if ($this->shouldUsePterodactylPanel()) {
            return $this->settingsService->getSetting(
                SettingEnum::PTERODACTYL_PANEL_URL->value
            );
        }

        return $this->settingsService->getSetting(
            SettingEnum::SITE_URL->value
        );
    }

    /**
     * Create a redirect response for a server
     * For use in controllers
     *
     * @param string $serverIdentifier Pterodactyl server identifier
     * @return RedirectResponse
     */
    public function createServerRedirect(string $serverIdentifier): RedirectResponse
    {
        if ($this->isSSOEnabled()) {
            return new RedirectResponse(
                $this->router->generate('sso_redirect', [
                    'redirect_path' => sprintf('/server/%s', $serverIdentifier)
                ])
            );
        }

        $pterodactylUrl = $this->settingsService->getSetting(
            SettingEnum::PTERODACTYL_PANEL_URL->value
        );
        $redirectUrl = rtrim($pterodactylUrl, '/') . '/server/' . $serverIdentifier;

        return new RedirectResponse($redirectUrl);
    }

    /**
     * Get route name and parameters for server redirect
     * For use in EasyAdmin actions
     *
     * @param string $serverIdentifier Pterodactyl server identifier
     * @return array{route: string|null, params: array|callable, url: string|null}
     */
    public function getServerRouteInfo(string $serverIdentifier): array
    {
        if ($this->isSSOEnabled()) {
            return [
                'route' => 'sso_redirect',
                'params' => [
                    'redirect_path' => sprintf('/server/%s', $serverIdentifier)
                ],
                'url' => null,
            ];
        }

        return [
            'route' => null,
            'params' => [],
            'url' => $this->settingsService->getSetting(
                SettingEnum::PTERODACTYL_PANEL_URL->value
            ),
        ];
    }

    /**
     * Determine if link should open in new tab
     *
     * @return bool True if should open in _blank
     */
    public function shouldOpenInNewTab(): bool
    {
        return $this->shouldUsePterodactylPanel();
    }
}
