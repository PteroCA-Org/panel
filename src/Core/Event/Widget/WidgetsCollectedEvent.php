<?php

namespace App\Core\Event\Widget;

use App\Core\Enum\WidgetContext;
use App\Core\Event\AbstractDomainEvent;
use App\Core\Service\Widget\WidgetRegistry;

/**
 * Event dispatched when collecting widgets for a specific context.
 *
 * Allows plugins to register custom widgets by subscribing to this event.
 * The event is context-aware, so plugins can register different widgets
 * for different contexts (dashboard, admin overview, etc.).
 *
 * @example Event Subscriber for dashboard widgets
 * public function onWidgetsCollected(WidgetsCollectedEvent $event): void
 * {
 *     if ($event->getWidgetContext() === WidgetContext::DASHBOARD) {
 *         $event->getRegistry()->registerWidget(new MyDashboardWidget());
 *     }
 * }
 *
 * @example Event Subscriber for admin overview widgets
 * public function onWidgetsCollected(WidgetsCollectedEvent $event): void
 * {
 *     if ($event->getWidgetContext() === WidgetContext::ADMIN_OVERVIEW) {
 *         $user = $event->getContextData()['user'] ?? null;
 *         if ($user && $user->hasPermission('access_admin_overview')) {
 *             $event->getRegistry()->registerWidget(new MyAdminWidget());
 *         }
 *     }
 * }
 *
 * @example Event Subscriber for multi-context widget
 * public function onWidgetsCollected(WidgetsCollectedEvent $event): void
 * {
 *     // This widget works in both contexts
 *     $event->getRegistry()->registerWidget(new UniversalAnalyticsWidget());
 * }
 */
class WidgetsCollectedEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly WidgetContext $widgetContext,
        private readonly array $contextData = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    /**
     * Get the widget registry to add/modify widgets.
     *
     * @return WidgetRegistry
     */
    public function getRegistry(): WidgetRegistry
    {
        return $this->registry;
    }

    /**
     * Get current widget rendering context.
     *
     * @return WidgetContext e.g., WidgetContext::DASHBOARD, WidgetContext::ADMIN_OVERVIEW
     */
    public function getWidgetContext(): WidgetContext
    {
        return $this->widgetContext;
    }

    /**
     * Get context-specific data.
     *
     * Data structure varies by context:
     * - DASHBOARD: ['user' => UserInterface]
     * - ADMIN_OVERVIEW: ['user' => UserInterface, 'statistics' => array, 'systemInformation' => array]
     * - SERVER_DETAIL: ['user' => UserInterface, 'server' => ServerInterface]
     *
     * @return array Context data
     */
    public function getContextData(): array
    {
        return $this->contextData;
    }
}
