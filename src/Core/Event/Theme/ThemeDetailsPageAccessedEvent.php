<?php

namespace App\Core\Event\Theme;

use App\Core\Event\AbstractDomainEvent;

class ThemeDetailsPageAccessedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly string $themeName,
        private readonly string $themeContext,
        private readonly array $context = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getThemeName(): string
    {
        return $this->themeName;
    }

    public function getThemeContext(): string
    {
        return $this->themeContext;
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
