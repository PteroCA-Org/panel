<?php

namespace App\Core\Event\Plugin;

use App\Core\Event\AbstractDomainEvent;

class PluginUploadRequestedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly string $fileName,
        private readonly bool $enableAfterUpload,
        private readonly array $context = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function isEnableAfterUpload(): bool
    {
        return $this->enableAfterUpload;
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
