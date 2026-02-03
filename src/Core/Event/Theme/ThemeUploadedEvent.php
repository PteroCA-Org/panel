<?php

namespace App\Core\Event\Theme;

use App\Core\Event\AbstractDomainEvent;

class ThemeUploadedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly string $themeName,
        private readonly string $version,
        private readonly bool $hasWarnings,
        private readonly int $warningCount,
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

    public function getVersion(): string
    {
        return $this->version;
    }

    public function hasWarnings(): bool
    {
        return $this->hasWarnings;
    }

    public function getWarningCount(): int
    {
        return $this->warningCount;
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
