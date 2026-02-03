<?php

namespace App\Core\Event\Theme;

use App\Core\Event\AbstractDomainEvent;

class ThemeIndexDataLoadedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly array $themes,
        private readonly int $themeCount,
        private readonly ?string $panelTheme = null,
        private readonly ?string $landingTheme = null,
        private readonly ?string $emailTheme = null,
        private readonly ?string $themeContext = null,
        private readonly array $context = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getThemeContext(): ?string
    {
        return $this->themeContext;
    }

    public function getThemes(): array
    {
        return $this->themes;
    }

    public function getThemeCount(): int
    {
        return $this->themeCount;
    }

    public function getPanelTheme(): ?string
    {
        return $this->panelTheme;
    }

    public function getLandingTheme(): ?string
    {
        return $this->landingTheme;
    }

    public function getEmailTheme(): ?string
    {
        return $this->emailTheme;
    }

    /**
     * @deprecated Use getPanelTheme(), getLandingTheme(), or getEmailTheme() instead
     */
    public function getActiveThemeName(): ?string
    {
        return $this->panelTheme;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getIp(): ?string
    {
        return $this->context['ip'] ?? null;
    }

    public function getUserAgent(): ?string
    {
        return $this->context['userAgent'] ?? null;
    }

    public function getLocale(): ?string
    {
        return $this->context['locale'] ?? null;
    }
}
