<?php

namespace App\Core\Event\Landing;

use App\Core\Event\AbstractDomainEvent;

/**
 * Event dispatched after landing page data has been loaded.
 *
 * This event is fired after categories and products are fetched from the database
 * and can be used for:
 * - Analytics on loaded data
 * - Data transformation or filtering
 * - Performance monitoring
 * - Cache warming
 */
class LandingPageDataLoadedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly ?int $userId,
        private readonly string $pageType,
        private readonly int $categoriesCount,
        private readonly int $productsCount,
        private readonly ?int $selectedCategoryId = null,
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

    public function getCategoriesCount(): int
    {
        return $this->categoriesCount;
    }

    public function getProductsCount(): int
    {
        return $this->productsCount;
    }

    public function getSelectedCategoryId(): ?int
    {
        return $this->selectedCategoryId;
    }

    public function hasSelectedCategory(): bool
    {
        return $this->selectedCategoryId !== null;
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
