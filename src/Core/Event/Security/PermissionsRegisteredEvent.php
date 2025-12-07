<?php

namespace App\Core\Event\Security;

use App\Core\Event\AbstractDomainEvent;
use App\Core\Service\Security\PermissionRegistry;

/**
 * Event dispatched when the system is collecting custom permissions.
 *
 * Allows plugins to register custom permissions that can be checked via isGranted().
 * This event is dispatched early in the application lifecycle (during kernel boot).
 *
 * @example Event Subscriber for registering permissions
 * public function onPermissionsRegistered(PermissionsRegisteredEvent $event): void
 * {
 *     $registry = $event->getRegistry();
 *
 *     // Register a permission for admins and moderators
 *     $registry->registerPermission(
 *         'PLUGIN_MY_PLUGIN_MANAGE',
 *         'Manage MyPlugin content',
 *         ['ROLE_ADMIN', 'ROLE_MODERATOR']
 *     );
 *
 *     // Register a permission for all authenticated users
 *     $registry->registerPermission(
 *         'PLUGIN_MY_PLUGIN_VIEW',
 *         'View MyPlugin content',
 *         ['ROLE_USER']
 *     );
 * }
 *
 * @example Event Subscriber with custom permission logic
 * public function onPermissionsRegistered(PermissionsRegisteredEvent $event): void
 * {
 *     $registry = $event->getRegistry();
 *
 *     // Register a permission with custom checking logic
 *     $registry->registerPermission(
 *         'PLUGIN_MY_PLUGIN_PREMIUM',
 *         'Access premium features',
 *         ['ROLE_USER'],
 *         function ($user, $subject, $attribute) {
 *             // Custom logic: check if user has active premium subscription
 *             return $user->hasPremiumSubscription();
 *         }
 *     );
 * }
 *
 * @example Using registered permissions in controllers
 * public function myAction(): Response
 * {
 *     // Check permission using isGranted()
 *     if (!$this->isGranted('PLUGIN_MY_PLUGIN_ADMIN')) {
 *         throw $this->createAccessDeniedException();
 *     }
 *
 *     // Permission granted, proceed with action
 *     return $this->render('my_plugin/admin.html.twig');
 * }
 *
 * @example Using in Twig templates
 * {% if is_granted('PLUGIN_MY_PLUGIN_ADMIN') %}
 *     <a href="{{ path('plugin_admin') }}">Admin Panel</a>
 * {% endif %}
 */
class PermissionsRegisteredEvent extends AbstractDomainEvent
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    /**
     * Get the permission registry to register custom permissions.
     *
     * @return PermissionRegistry
     */
    public function getRegistry(): PermissionRegistry
    {
        return $this->registry;
    }
}
