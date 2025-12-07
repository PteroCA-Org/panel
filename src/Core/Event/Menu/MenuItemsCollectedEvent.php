<?php

namespace App\Core\Event\Menu;

use App\Core\Event\AbstractDomainEvent;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Event dispatched when collecting menu items for the admin panel navigation.
 *
 * Allows plugins to register custom menu items and sections by subscribing to this event.
 * Menu items are organized into sections (main, admin, footer) for better organization.
 *
 * @example Adding a menu item to the main section
 * public function onMenuItemsCollected(MenuItemsCollectedEvent $event): void
 * {
 *     $event->addMenuItem('main',
 *         MenuItem::linkToRoute(
 *             'My Plugin',
 *             'fa fa-puzzle-piece',
 *             'plugin_my_plugin_dashboard'
 *         )
 *     );
 * }
 *
 * @example Adding a menu item to the admin section (with permission check)
 * public function onMenuItemsCollected(MenuItemsCollectedEvent $event): void
 * {
 *     if ($event->getUser()->hasPermission('access_admin_panel')) {
 *         $event->addMenuItem('admin',
 *             MenuItem::linkToCrud(
 *                 'Plugin Settings',
 *                 'fa fa-cog',
 *                 PluginSetting::class
 *             )
 *         );
 *     }
 * }
 *
 * @example Creating a new menu section
 * public function onMenuItemsCollected(MenuItemsCollectedEvent $event): void
 * {
 *     $event->addMenuSection('my_section',
 *         MenuItem::section('My Plugin Section')
 *     );
 *
 *     $event->addMenuItem('my_section',
 *         MenuItem::linkToRoute('Item 1', 'fa fa-star', 'route_1')
 *     );
 * }
 *
 * @example Adding a submenu
 * public function onMenuItemsCollected(MenuItemsCollectedEvent $event): void
 * {
 *     $event->addMenuItem('main',
 *         MenuItem::subMenu('My Plugin', 'fa fa-puzzle-piece')->setSubItems([
 *             MenuItem::linkToRoute('Dashboard', 'fa fa-home', 'plugin_dashboard'),
 *             MenuItem::linkToRoute('Settings', 'fa fa-cog', 'plugin_settings'),
 *         ])
 *     );
 * }
 */
class MenuItemsCollectedEvent extends AbstractDomainEvent
{
    /**
     * @var array<string, MenuItem[]>
     */
    private array $menuItems;

    public function __construct(
        private readonly UserInterface $user,
        array $menuItems = [],
        private readonly array $context = [],
        ?string $eventId = null,
    ) {
        parent::__construct($eventId);
        $this->menuItems = $menuItems;
    }

    /**
     * Add a menu item to a specific section.
     *
     * @param string $section Section name (e.g., 'main', 'admin', 'footer')
     * @param MenuItem $item Menu item to add
     * @return void
     */
    public function addMenuItem(string $section, MenuItem $item): void
    {
        if (!isset($this->menuItems[$section])) {
            $this->menuItems[$section] = [];
        }

        $this->menuItems[$section][] = $item;
    }

    /**
     * Add a new menu section.
     *
     * This creates a new section and typically adds a section header.
     *
     * @param string $sectionName Unique section name
     * @param MenuItem $sectionItem Section header (use MenuItem::section())
     * @return void
     */
    public function addMenuSection(string $sectionName, MenuItem $sectionItem): void
    {
        if (!isset($this->menuItems[$sectionName])) {
            $this->menuItems[$sectionName] = [];
        }

        array_unshift($this->menuItems[$sectionName], $sectionItem);
    }

    /**
     * Get all menu items grouped by section.
     *
     * @return array<string, MenuItem[]>
     */
    public function getMenuItems(): array
    {
        return $this->menuItems;
    }

    /**
     * Set all menu items (replaces existing).
     *
     * Used internally by DashboardController to set initial items.
     *
     * @param array<string, MenuItem[]> $menuItems
     * @return void
     */
    public function setMenuItems(array $menuItems): void
    {
        $this->menuItems = $menuItems;
    }

    /**
     * Get menu items for a specific section.
     *
     * @param string $section Section name
     * @return MenuItem[]
     */
    public function getMenuItemsForSection(string $section): array
    {
        return $this->menuItems[$section] ?? [];
    }

    /**
     * Check if a section exists.
     *
     * @param string $section Section name
     * @return bool
     */
    public function hasSection(string $section): bool
    {
        return isset($this->menuItems[$section]);
    }

    /**
     * Get the current user.
     *
     * Useful for role-based menu item additions.
     *
     * @return UserInterface
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Get event context data.
     *
     * Contains request metadata like IP, user agent, locale.
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get all section names.
     *
     * @return string[]
     */
    public function getSections(): array
    {
        return array_keys($this->menuItems);
    }
}
