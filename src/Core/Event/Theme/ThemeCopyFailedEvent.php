<?php

namespace App\Core\Event\Theme;

use App\Core\Event\AbstractDomainEvent;

class ThemeCopyFailedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly string $sourceThemeName,
        private readonly string $newThemeName,
        private readonly string $themeContext,
        private readonly string $errorMessage,
        private readonly array $context = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getSourceThemeName(): string
    {
        return $this->sourceThemeName;
    }

    public function getNewThemeName(): string
    {
        return $this->newThemeName;
    }

    public function getThemeContext(): string
    {
        return $this->themeContext;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
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
