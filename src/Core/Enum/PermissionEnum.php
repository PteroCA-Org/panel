<?php

namespace App\Core\Enum;

enum PermissionEnum: string
{
    // Dashboard (2)
    case ACCESS_DASHBOARD = 'access_dashboard';
    case ACCESS_ADMIN_OVERVIEW = 'access_admin_overview';

    // User Management (5)
    case ACCESS_USERS = 'access_users';
    case CREATE_USER = 'create_user';
    case EDIT_USER = 'edit_user';
    case DELETE_USER = 'delete_user';
    case VIEW_USER = 'view_user';

    // Server Management (10)
    case ACCESS_SERVERS = 'access_servers';
    case CREATE_SERVER = 'create_server';
    case EDIT_SERVER = 'edit_server';
    case DELETE_SERVER = 'delete_server';
    case VIEW_SERVER = 'view_server';
    case ACCESS_SERVER_LOGS = 'access_server_logs';
    case ACCESS_SERVER_PRODUCTS = 'access_server_products';
    case VIEW_SERVER_PRODUCT = 'view_server_product';
    case EDIT_SERVER_PRODUCT = 'edit_server_product';
    case DELETE_SERVER_PRODUCT = 'delete_server_product';

    // Shop (12)
    case ACCESS_SHOP = 'access_shop';
    case ACCESS_CATEGORIES = 'access_categories';
    case CREATE_CATEGORY = 'create_category';
    case EDIT_CATEGORY = 'edit_category';
    case DELETE_CATEGORY = 'delete_category';
    case VIEW_CATEGORY = 'view_category';
    case ACCESS_PRODUCTS = 'access_products';
    case CREATE_PRODUCT = 'create_product';
    case EDIT_PRODUCT = 'edit_product';
    case DELETE_PRODUCT = 'delete_product';
    case VIEW_PRODUCT = 'view_product';
    case COPY_PRODUCT = 'copy_product';

    // Payment (3)
    case ACCESS_WALLET = 'access_wallet';
    case ACCESS_PAYMENTS = 'access_payments';
    case VIEW_PAYMENT = 'view_payment';

    // Voucher (8)
    case ACCESS_VOUCHERS = 'access_vouchers';
    case CREATE_VOUCHER = 'create_voucher';
    case EDIT_VOUCHER = 'edit_voucher';
    case DELETE_VOUCHER = 'delete_voucher';
    case VIEW_VOUCHER = 'view_voucher';
    case ACCESS_VOUCHER_USAGES = 'access_voucher_usages';
    case VIEW_VOUCHER_USAGE = 'view_voucher_usage';
    case SHOW_VOUCHER_USAGES = 'show_voucher_usages';

    // Logs (6)
    case ACCESS_SYSTEM_LOGS = 'access_system_logs';
    case ACCESS_EMAIL_LOGS = 'access_email_logs';
    case ACCESS_LOGS = 'access_logs';
    case VIEW_LOG = 'view_log';
    case VIEW_EMAIL_LOG = 'view_email_log';
    case VIEW_SERVER_LOG = 'view_server_log';

    // Settings - Access (7)
    case ACCESS_SETTINGS_GENERAL = 'access_settings_general';
    case ACCESS_SETTINGS_PTERODACTYL = 'access_settings_pterodactyl';
    case ACCESS_SETTINGS_SECURITY = 'access_settings_security';
    case ACCESS_SETTINGS_PAYMENT = 'access_settings_payment';
    case ACCESS_SETTINGS_EMAIL = 'access_settings_email';
    case ACCESS_SETTINGS_THEME = 'access_settings_theme';
    case ACCESS_SETTINGS_PLUGIN = 'access_settings_plugin';

    // Settings - Edit (7)
    case EDIT_SETTINGS_GENERAL = 'edit_settings_general';
    case EDIT_SETTINGS_PTERODACTYL = 'edit_settings_pterodactyl';
    case EDIT_SETTINGS_SECURITY = 'edit_settings_security';
    case EDIT_SETTINGS_PAYMENT = 'edit_settings_payment';
    case EDIT_SETTINGS_EMAIL = 'edit_settings_email';
    case EDIT_SETTINGS_THEME = 'edit_settings_theme';
    case EDIT_SETTINGS_PLUGIN = 'edit_settings_plugin';

    // Plugins (8)
    case ACCESS_PLUGINS = 'access_plugins';
    case VIEW_PLUGIN = 'view_plugin';
    case ENABLE_PLUGIN = 'enable_plugin';
    case DISABLE_PLUGIN = 'disable_plugin';
    case INSTALL_PLUGIN = 'install_plugin';
    case UNINSTALL_PLUGIN = 'uninstall_plugin';
    case UPLOAD_PLUGIN = 'upload_plugin';
    case CONFIGURE_PLUGIN = 'configure_plugin';

    // Themes (8)
    case ACCESS_THEMES = 'access_themes';
    case VIEW_THEME = 'view_theme';
    case SET_DEFAULT_THEME = 'set_default_theme';
    case CONFIGURE_THEME = 'configure_theme';
    case UPLOAD_THEME = 'upload_theme';
    case COPY_THEME = 'copy_theme';
    case EXPORT_THEME = 'export_theme';
    case DELETE_THEME = 'delete_theme';

    // Role Management (7)
    case ACCESS_ROLES = 'access_roles';
    case CREATE_ROLE = 'create_role';
    case EDIT_ROLE = 'edit_role';
    case DELETE_ROLE = 'delete_role';
    case VIEW_ROLE = 'view_role';
    case ACCESS_PERMISSIONS = 'access_permissions';
    case VIEW_PERMISSION = 'view_permission';

    // User Features (9)
    case ACCESS_MY_ACCOUNT = 'access_my_account';
    case ACCESS_MY_SERVERS = 'access_my_servers';
    case ACCESS_USER_PAYMENTS = 'access_user_payments';
    case VIEW_USER_PAYMENT = 'view_user_payment';
    case EDIT_USER_ACCOUNT = 'edit_user_account';
    case CONTINUE_PAYMENT = 'continue_payment';
    case PURCHASE_SERVER = 'purchase_server';
    case RENEW_SERVER = 'renew_server';
    case ACCESS_PTERODACTYL_SSO = 'access_pterodactyl_sso';

    // Pterodactyl Integration (1)
    case PTERODACTYL_ROOT_ADMIN = 'pterodactyl_root_admin';

    /**
     * Get permissions grouped by section.
     * Useful for admin UI, permission management screens.
     *
     * @return array<string, array<self>>
     */
    public static function getPermissionGroups(): array
    {
        return [
            'dashboard' => [
                self::ACCESS_DASHBOARD,
                self::ACCESS_ADMIN_OVERVIEW,
            ],
            'user_management' => [
                self::ACCESS_USERS,
                self::CREATE_USER,
                self::EDIT_USER,
                self::DELETE_USER,
                self::VIEW_USER,
            ],
            'server_management' => [
                self::ACCESS_SERVERS,
                self::CREATE_SERVER,
                self::EDIT_SERVER,
                self::DELETE_SERVER,
                self::VIEW_SERVER,
                self::ACCESS_SERVER_LOGS,
                self::ACCESS_SERVER_PRODUCTS,
                self::VIEW_SERVER_PRODUCT,
                self::EDIT_SERVER_PRODUCT,
                self::DELETE_SERVER_PRODUCT,
            ],
            'shop' => [
                self::ACCESS_SHOP,
                self::ACCESS_CATEGORIES,
                self::CREATE_CATEGORY,
                self::EDIT_CATEGORY,
                self::DELETE_CATEGORY,
                self::VIEW_CATEGORY,
                self::ACCESS_PRODUCTS,
                self::CREATE_PRODUCT,
                self::EDIT_PRODUCT,
                self::DELETE_PRODUCT,
                self::VIEW_PRODUCT,
                self::COPY_PRODUCT,
            ],
            'payment' => [
                self::ACCESS_WALLET,
                self::ACCESS_PAYMENTS,
                self::VIEW_PAYMENT,
            ],
            'voucher' => [
                self::ACCESS_VOUCHERS,
                self::CREATE_VOUCHER,
                self::EDIT_VOUCHER,
                self::DELETE_VOUCHER,
                self::VIEW_VOUCHER,
                self::ACCESS_VOUCHER_USAGES,
                self::VIEW_VOUCHER_USAGE,
                self::SHOW_VOUCHER_USAGES,
            ],
            'logs' => [
                self::ACCESS_SYSTEM_LOGS,
                self::ACCESS_EMAIL_LOGS,
                self::ACCESS_LOGS,
                self::VIEW_LOG,
                self::VIEW_EMAIL_LOG,
                self::VIEW_SERVER_LOG,
            ],
            'settings' => [
                self::ACCESS_SETTINGS_GENERAL,
                self::ACCESS_SETTINGS_PTERODACTYL,
                self::ACCESS_SETTINGS_SECURITY,
                self::ACCESS_SETTINGS_PAYMENT,
                self::ACCESS_SETTINGS_EMAIL,
                self::ACCESS_SETTINGS_THEME,
                self::ACCESS_SETTINGS_PLUGIN,
                self::EDIT_SETTINGS_GENERAL,
                self::EDIT_SETTINGS_PTERODACTYL,
                self::EDIT_SETTINGS_SECURITY,
                self::EDIT_SETTINGS_PAYMENT,
                self::EDIT_SETTINGS_EMAIL,
                self::EDIT_SETTINGS_THEME,
                self::EDIT_SETTINGS_PLUGIN,
            ],
            'plugins' => [
                self::ACCESS_PLUGINS,
                self::VIEW_PLUGIN,
                self::ENABLE_PLUGIN,
                self::DISABLE_PLUGIN,
                self::INSTALL_PLUGIN,
                self::UNINSTALL_PLUGIN,
                self::UPLOAD_PLUGIN,
                self::CONFIGURE_PLUGIN,
            ],
            'themes' => [
                self::ACCESS_THEMES,
                self::VIEW_THEME,
                self::SET_DEFAULT_THEME,
                self::CONFIGURE_THEME,
                self::UPLOAD_THEME,
                self::COPY_THEME,
                self::EXPORT_THEME,
                self::DELETE_THEME,
            ],
            'role_management' => [
                self::ACCESS_ROLES,
                self::CREATE_ROLE,
                self::EDIT_ROLE,
                self::DELETE_ROLE,
                self::VIEW_ROLE,
                self::ACCESS_PERMISSIONS,
                self::VIEW_PERMISSION,
            ],
            'user_features' => [
                self::ACCESS_MY_ACCOUNT,
                self::ACCESS_MY_SERVERS,
                self::ACCESS_USER_PAYMENTS,
                self::VIEW_USER_PAYMENT,
                self::EDIT_USER_ACCOUNT,
                self::CONTINUE_PAYMENT,
                self::PURCHASE_SERVER,
                self::RENEW_SERVER,
                self::ACCESS_PTERODACTYL_SSO,
            ],
            'pterodactyl_integration' => [
                self::PTERODACTYL_ROOT_ADMIN,
            ],
        ];
    }

    /**
     * Get the section for this permission.
     *
     * @return string The section name (e.g., 'dashboard', 'user_management')
     */
    public function getSection(): string
    {
        $groups = self::getPermissionGroups();

        foreach ($groups as $section => $permissions) {
            if (in_array($this, $permissions, true)) {
                return $section;
            }
        }

        return 'unknown';
    }

    /**
     * Safe conversion from string to enum.
     * Returns null if string doesn't match any permission.
     *
     * @param string $code Permission code
     * @return self|null
     */
    public static function fromString(string $code): ?self
    {
        return self::tryFrom($code);
    }

    /**
     * Check if a permission code is a core system permission.
     * Plugin permissions start with 'PLUGIN_' and are not in this enum.
     *
     * @param string $code Permission code to check
     * @return bool
     */
    public static function isCorePermission(string $code): bool
    {
        return self::tryFrom($code) !== null;
    }

    /**
     * Get all permission codes as strings.
     * Useful for validation and comparison with database.
     *
     * @return string[]
     */
    public static function getAllCodes(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
