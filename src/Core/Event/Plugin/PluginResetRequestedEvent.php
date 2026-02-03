<?php

namespace App\Core\Event\Plugin;

use App\Core\Event\AbstractDomainEvent;

class PluginResetRequestedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly string $pluginName,
        private readonly ?string $previousFaultReason,
        private readonly array $context = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getPluginName(): string
    {
        return $this->pluginName;
    }

    public function getPreviousFaultReason(): ?string
    {
        return $this->previousFaultReason;
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
