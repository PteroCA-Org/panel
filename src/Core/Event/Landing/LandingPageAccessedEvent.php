<?php

namespace App\Core\Event\Landing;

use App\Core\Event\AbstractDomainEvent;

/**
 * Event dispatched when a user accesses the landing page.
 *
 * This event is fired before any data is loaded and can be used for:
 * - Analytics tracking
 * - Access logging
 * - Pre-processing tasks
 * - Visitor tracking
 */
class LandingPageAccessedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly string $pageType,
        private readonly array $context = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getPageType(): string
    {
        return $this->pageType;
    }

    public function isHomepage(): bool
    {
        return $this->pageType === 'homepage';
    }

    public function isStore(): bool
    {
        return $this->pageType === 'store';
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
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
