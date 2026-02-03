<?php

namespace App\Core\Event\Landing;

use App\Core\Event\AbstractDomainEvent;

/**
 * Event dispatched when collecting navigation buttons for the landing page header.
 *
 * This is the PRIMARY CUSTOMIZATION POINT for plugins to add, modify, or remove
 * navigation buttons in the landing page header.
 *
 * Plugins can subscribe to this event to:
 * - Add custom buttons (Login, Register, Dashboard, etc.)
 * - Remove default buttons
 * - Modify button properties (style, position, priority)
 * - Add conditional buttons based on user state
 *
 * Button Structure:
 * [
 *     'type' => 'primary|secondary|outline|link',
 *     'label' => 'Button Text (translation key)',
 *     'url' => '/path/to/action',
 *     'icon' => 'fas fa-icon', // optional
 *     'position' => 'left|right', // default: 'right'
 *     'priority' => 100, // higher = displayed first
 *     'condition' => 'authenticated|guest|always', // default: 'always'
 *     'attributes' => ['target' => '_blank'], // optional HTML attributes
 * ]
 */
class NavigationButtonsCollectedEvent extends AbstractDomainEvent
{
    private array $buttons = [];

    public function __construct(
        private readonly ?object $user,
        array $defaultButtons = [],
        private readonly string $context = 'landing_page',
        private readonly array $eventContext = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
        $this->buttons = $defaultButtons;
    }

    /**
     * Add a navigation button.
     *
     * @param string $label Button label (translation key)
     * @param string $url Button URL
     * @param string $type Button type: 'primary', 'secondary', 'outline', 'link'
     * @param array $options Additional options:
     *   - icon: FontAwesome icon class
     *   - position: 'left' or 'right' (default: 'right')
     *   - priority: int (higher = displayed first, default: 50)
     *   - condition: 'authenticated', 'guest', 'always' (default: 'always')
     *   - attributes: array of HTML attributes
     */
    public function addButton(string $label, string $url, string $type = 'link', array $options = []): void
    {
        $this->buttons[] = [
            'type' => $type,
            'label' => $label,
            'url' => $url,
            'icon' => $options['icon'] ?? null,
            'position' => $options['position'] ?? 'right',
            'priority' => $options['priority'] ?? 50,
            'condition' => $options['condition'] ?? 'always',
            'attributes' => $options['attributes'] ?? [],
        ];
    }

    /**
     * Get all buttons, filtered by conditions.
     */
    public function getButtons(): array
    {
        $isAuthenticated = $this->user !== null;

        return array_filter($this->buttons, function ($button) use ($isAuthenticated) {
            $condition = $button['condition'] ?? 'always';

            return match ($condition) {
                'authenticated' => $isAuthenticated,
                'guest' => !$isAuthenticated,
                'always' => true,
                default => true,
            };
        });
    }

    /**
     * Get buttons for a specific position.
     */
    public function getButtonsForPosition(string $position): array
    {
        return array_filter($this->getButtons(), fn($button) => ($button['position'] ?? 'right') === $position);
    }

    /**
     * Remove a button by label.
     */
    public function removeButton(string $label): void
    {
        $this->buttons = array_filter($this->buttons, fn($button) => $button['label'] !== $label);
    }

    /**
     * Get the current user (null for guests).
     */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /**
     * Check if user is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /**
     * Check if user is guest.
     */
    public function isGuest(): bool
    {
        return $this->user === null;
    }

    /**
     * Get the event context (landing_page).
     */
    public function getContext(): string
    {
        return $this->context;
    }

    /**
     * Get the request context.
     */
    public function getEventContext(): array
    {
        return $this->eventContext;
    }

    public function getIp(): ?string
    {
        return $this->eventContext['ip'] ?? null;
    }

    public function getUserAgent(): ?string
    {
        return $this->eventContext['userAgent'] ?? null;
    }

    public function getLocale(): ?string
    {
        return $this->eventContext['locale'] ?? null;
    }
}
