<?php

namespace App\Core\Service\Menu;

use App\Core\Entity\Category;
use App\Core\Entity\EmailLog;
use App\Core\Entity\Log;
use App\Core\Entity\Panel\UserAccount;
use App\Core\Entity\Panel\UserPayment;
use App\Core\Entity\Payment;
use App\Core\Entity\Permission;
use App\Core\Entity\Plugin;
use App\Core\Entity\Product;
use App\Core\Entity\Role;
use App\Core\Entity\Server;
use App\Core\Entity\ServerLog;
use App\Core\Entity\User;
use App\Core\Entity\Voucher;
use App\Core\Entity\VoucherUsage;
use App\Core\Controller\Panel\Setting\PluginCrudController;
use App\Core\Controller\Panel\Setting\ThemeCrudController;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingContextEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\SubMenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

class MenuBuilder
{
    public function __construct(
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {}

    /**
     * Build the complete menu structure with permission checks
     *
     * @param callable $settingsUrlGenerator Callback to generate settings URLs
     * @return array Associative array with 'main', 'admin', 'footer' sections
     */
    public function buildMenuStructure(callable $settingsUrlGenerator): array
    {
        $menuItems = [];

        $menuItems['main'] = $this->buildMainSection();

        if ($this->security->isGranted(PermissionEnum::ACCESS_ADMIN_OVERVIEW->value)) {
            $menuItems['admin'] = $this->buildAdminSection($settingsUrlGenerator);
        }

        $menuItems['footer'] = $this->buildFooterSection();

        return $menuItems;
    }

    /**
     * Check if user has at least one of the given permissions
     */
    private function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->security->isGranted($permission)) {
                return true;
            }
        }
        return false;
    }

    private function buildMainSection(): array
    {
        $mainItems = [
            MenuItem::section($this->translator->trans('pteroca.crud.menu.menu')),
            MenuItem::linkToDashboard($this->translator->trans('pteroca.crud.menu.dashboard'), 'fa fa-home'),
        ];

        // My Servers
        if ($this->security->isGranted(PermissionEnum::ACCESS_MY_SERVERS->value)) {
            $mainItems[] = MenuItem::linkToRoute(
                $this->translator->trans('pteroca.crud.menu.my_servers'),
                'fa fa-server',
                'servers'
            );
        }

        // Shop
        if ($this->security->isGranted(PermissionEnum::ACCESS_SHOP->value)) {
            $mainItems[] = MenuItem::linkToRoute(
                $this->translator->trans('pteroca.crud.menu.shop'),
                'fa fa-shopping-cart',
                'panel_store'
            );
        }

        // Wallet
        if ($this->security->isGranted(PermissionEnum::ACCESS_WALLET->value)) {
            $mainItems[] = MenuItem::linkToRoute(
                $this->translator->trans('pteroca.crud.menu.wallet'),
                'fa fa-wallet',
                'recharge_balance'
            );
        }

        // My Account (always visible for authenticated users)
        $mainItems[] = MenuItem::subMenu($this->translator->trans('pteroca.crud.menu.my_account'), 'fa fa-user')->setSubItems([
            MenuItem::linkToCrud($this->translator->trans('pteroca.crud.menu.payments'), 'fa fa-money', UserPayment::class),
            MenuItem::linkToCrud($this->translator->trans('pteroca.crud.menu.account_settings'), 'fa fa-user-cog', UserAccount::class),
        ]);

        return $mainItems;
    }

    private function buildAdminSection(callable $settingsUrlGenerator): array
    {
        $adminItems = [
            MenuItem::section($this->translator->trans('pteroca.crud.menu.administration')),
        ];

        // Overview (always visible if user has ACCESS_ADMIN_OVERVIEW)
        $adminItems[] = MenuItem::linkToRoute(
            $this->translator->trans('pteroca.crud.menu.overview'),
            'fa fa-gauge',
            'admin_overview'
        );

        // Shop submenu
        $shopSubmenu = $this->buildShopSubmenu();
        if ($shopSubmenu !== null) {
            $adminItems[] = $shopSubmenu;
        }

        // Servers
        if ($this->security->isGranted(PermissionEnum::ACCESS_SERVERS->value)) {
            $adminItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.servers'),
                'fa fa-server',
                Server::class
            );
        }

        // Payments
        if ($this->security->isGranted(PermissionEnum::ACCESS_PAYMENTS->value)) {
            $adminItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.payments'),
                'fa fa-money',
                Payment::class
            );
        }

        // Logs submenu
        $logsSubmenu = $this->buildLogsSubmenu();
        if ($logsSubmenu !== null) {
            $adminItems[] = $logsSubmenu;
        }

        // Settings submenu
        $settingsSubmenu = $this->buildSettingsSubmenu($settingsUrlGenerator);
        if ($settingsSubmenu !== null) {
            $adminItems[] = $settingsSubmenu;
        }

        // Themes submenu
        $themesSubmenu = $this->buildThemesSubmenu();
        if ($themesSubmenu !== null) {
            $adminItems[] = $themesSubmenu;
        }

        // Plugins submenu
        $pluginsSubmenu = $this->buildPluginsSubmenu();
        if ($pluginsSubmenu !== null) {
            $adminItems[] = $pluginsSubmenu;
        }

        // Users
        if ($this->security->isGranted(PermissionEnum::ACCESS_USERS->value)) {
            $adminItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.users'),
                'fa fa-user',
                User::class
            );
        }

        // Roles & Permissions submenu
        $rolesPermissionsSubmenu = $this->buildRolesPermissionsSubmenu();
        if ($rolesPermissionsSubmenu !== null) {
            $adminItems[] = $rolesPermissionsSubmenu;
        }

        // Vouchers submenu
        $vouchersSubmenu = $this->buildVouchersSubmenu();
        if ($vouchersSubmenu !== null) {
            $adminItems[] = $vouchersSubmenu;
        }

        return $adminItems;
    }

    private function buildShopSubmenu(): ?SubMenuItem
    {
        if (!$this->hasAnyPermission([
            PermissionEnum::ACCESS_CATEGORIES->value,
            PermissionEnum::ACCESS_PRODUCTS->value,
        ])) {
            return null;
        }

        $shopItems = [];

        if ($this->security->isGranted(PermissionEnum::ACCESS_CATEGORIES->value)) {
            $shopItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.categories'),
                'fa fa-list',
                Category::class
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_PRODUCTS->value)) {
            $shopItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.products'),
                'fa fa-sliders-h',
                Product::class
            );
        }

        if (empty($shopItems)) {
            return null;
        }

        return MenuItem::subMenu(
            $this->translator->trans('pteroca.crud.menu.shop'),
            'fa fa-shopping-cart'
        )->setSubItems($shopItems);
    }

    private function buildLogsSubmenu(): ?SubMenuItem
    {
        if (!$this->hasAnyPermission([
            PermissionEnum::ACCESS_SYSTEM_LOGS->value,
            PermissionEnum::ACCESS_EMAIL_LOGS->value,
            PermissionEnum::ACCESS_SERVER_LOGS->value,
        ])) {
            return null;
        }

        $logItems = [];

        if ($this->security->isGranted(PermissionEnum::ACCESS_SYSTEM_LOGS->value)) {
            $logItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.logs'),
                'fa fa-bars-staggered',
                Log::class
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_EMAIL_LOGS->value)) {
            $logItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.email_logs'),
                'fa fa-envelope',
                EmailLog::class
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_SERVER_LOGS->value)) {
            $logItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.server_logs'),
                'fa fa-bars',
                ServerLog::class
            );
        }

        if (empty($logItems)) {
            return null;
        }

        return MenuItem::subMenu(
            $this->translator->trans('pteroca.crud.menu.logs'),
            'fa fa-bars-staggered'
        )->setSubItems($logItems);
    }

    private function buildSettingsSubmenu(callable $settingsUrlGenerator): ?SubMenuItem
    {
        $settingsPermissions = [
            PermissionEnum::ACCESS_SETTINGS_GENERAL->value,
            PermissionEnum::ACCESS_SETTINGS_PTERODACTYL->value,
            PermissionEnum::ACCESS_SETTINGS_SECURITY->value,
            PermissionEnum::ACCESS_SETTINGS_PAYMENT->value,
            PermissionEnum::ACCESS_SETTINGS_EMAIL->value,
            PermissionEnum::ACCESS_SETTINGS_THEME->value,
        ];

        if (!$this->hasAnyPermission($settingsPermissions)) {
            return null;
        }

        $settingsItems = [];

        if ($this->security->isGranted(PermissionEnum::ACCESS_SETTINGS_GENERAL->value)) {
            $settingsItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.crud.menu.general'),
                'fa fa-cog',
                $settingsUrlGenerator(SettingContextEnum::GENERAL)
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_SETTINGS_PTERODACTYL->value)) {
            $settingsItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.crud.menu.pterodactyl'),
                'fa fa-network-wired',
                $settingsUrlGenerator(SettingContextEnum::PTERODACTYL)
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_SETTINGS_SECURITY->value)) {
            $settingsItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.crud.menu.security'),
                'fa fa-shield-halved',
                $settingsUrlGenerator(SettingContextEnum::SECURITY)
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_SETTINGS_PAYMENT->value)) {
            $settingsItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.crud.menu.payment_gateways'),
                'fa fa-hand-holding-dollar',
                $settingsUrlGenerator(SettingContextEnum::PAYMENT)
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_SETTINGS_EMAIL->value)) {
            $settingsItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.crud.menu.email'),
                'fa fa-envelope',
                $settingsUrlGenerator(SettingContextEnum::EMAIL)
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_SETTINGS_THEME->value)) {
            $settingsItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.crud.menu.appearance'),
                'fa fa-brush',
                $settingsUrlGenerator(SettingContextEnum::THEME)
            );
        }

        if (empty($settingsItems)) {
            return null;
        }

        return MenuItem::subMenu(
            $this->translator->trans('pteroca.crud.menu.settings'),
            'fa fa-cogs'
        )->setSubItems($settingsItems);
    }

    private function buildThemesSubmenu(): ?SubMenuItem
    {
        if (!$this->security->isGranted(PermissionEnum::ACCESS_THEMES->value)) {
            return null;
        }

        $themeItems = [];

        $themeItems[] = MenuItem::linkToUrl(
            $this->translator->trans('pteroca.crud.menu.manage_themes'),
            'fa fa-brush',
            $this->adminUrlGenerator
                ->setController(ThemeCrudController::class)
                ->setAction('index')
                ->generateUrl()
        );

        if ($this->security->isGranted(PermissionEnum::UPLOAD_THEME->value)) {
            $themeItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.theme.upload.upload_theme'),
                'fa fa-upload',
                $this->adminUrlGenerator
                    ->setRoute('admin_theme_upload')
                    ->generateUrl()
            );
        }

        return MenuItem::subMenu(
            $this->translator->trans('pteroca.crud.menu.themes'),
            'fa fa-brush'
        )->setSubItems($themeItems);
    }

    private function buildPluginsSubmenu(): ?SubMenuItem
    {
        if (!$this->security->isGranted(PermissionEnum::ACCESS_PLUGINS->value)) {
            return null;
        }

        $pluginItems = [];

        $pluginItems[] = MenuItem::linkToCrud(
            $this->translator->trans('pteroca.crud.plugin.manage_plugins'),
            'fa fa-list',
            Plugin::class
        );

        if ($this->security->isGranted(PermissionEnum::UPLOAD_PLUGIN->value)) {
            $pluginItems[] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.plugin.upload.upload_plugin'),
                'fa fa-upload',
                $this->adminUrlGenerator
                    ->setController(PluginCrudController::class)
                    ->setAction('uploadPlugin')
                    ->generateUrl()
            );
        }

        return MenuItem::subMenu(
            $this->translator->trans('pteroca.crud.plugin.plugins'),
            'fa fa-puzzle-piece'
        )->setSubItems($pluginItems);
    }

    private function buildVouchersSubmenu(): ?SubMenuItem
    {
        if (!$this->hasAnyPermission([
            PermissionEnum::ACCESS_VOUCHERS->value,
            PermissionEnum::ACCESS_VOUCHER_USAGES->value,
        ])) {
            return null;
        }

        $voucherItems = [];

        if ($this->security->isGranted(PermissionEnum::ACCESS_VOUCHERS->value)) {
            $voucherItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.vouchers'),
                'fa fa-gift',
                Voucher::class
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_VOUCHER_USAGES->value)) {
            $voucherItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.voucher_usages'),
                'fa fa-list',
                VoucherUsage::class
            );
        }

        if (empty($voucherItems)) {
            return null;
        }

        return MenuItem::subMenu(
            $this->translator->trans('pteroca.crud.menu.vouchers'),
            'fa fa-gifts'
        )->setSubItems($voucherItems);
    }

    private function buildRolesPermissionsSubmenu(): ?SubMenuItem
    {
        if (!$this->hasAnyPermission([
            PermissionEnum::ACCESS_ROLES->value,
            PermissionEnum::ACCESS_PERMISSIONS->value,
        ])) {
            return null;
        }

        $rolesPermissionsItems = [];

        if ($this->security->isGranted(PermissionEnum::ACCESS_ROLES->value)) {
            $rolesPermissionsItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.roles'),
                'fa fa-user-tag',
                Role::class
            );
        }

        if ($this->security->isGranted(PermissionEnum::ACCESS_PERMISSIONS->value)) {
            $rolesPermissionsItems[] = MenuItem::linkToCrud(
                $this->translator->trans('pteroca.crud.menu.permissions'),
                'fa fa-lock',
                Permission::class
            );
        }

        if (empty($rolesPermissionsItems)) {
            return null;
        }

        return MenuItem::subMenu(
            $this->translator->trans('pteroca.crud.menu.roles_and_permissions'),
            'fa fa-user-shield'
        )->setSubItems($rolesPermissionsItems);
    }

    private function buildFooterSection(): array
    {
        return [
            MenuItem::section(),
        ];
    }
}
