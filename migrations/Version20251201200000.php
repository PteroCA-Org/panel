<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251201200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds role-based permission system with backward compatibility';
    }

    public function up(Schema $schema): void
    {
        // Create role table
        $this->addSql('CREATE TABLE role (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            is_system TINYINT(1) DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_57698A6A5E237E06 (name),
            INDEX idx_role_system (is_system),
            INDEX idx_role_name (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create permission table
        $this->addSql('CREATE TABLE permission (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            section VARCHAR(100) NOT NULL,
            is_system TINYINT(1) DEFAULT 0 NOT NULL,
            plugin_name VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_E04992AA77153098 (code),
            INDEX idx_permission_code (code),
            INDEX idx_permission_plugin (plugin_name),
            INDEX idx_permission_system (is_system),
            INDEX idx_permission_section (section),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create role_permission junction table
        $this->addSql('CREATE TABLE role_permission (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY(role_id, permission_id),
            INDEX IDX_6F7DF886D60322AC (role_id),
            INDEX IDX_6F7DF886FED90CCA (permission_id),
            CONSTRAINT FK_6F7DF886D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE,
            CONSTRAINT FK_6F7DF886FED90CCA FOREIGN KEY (permission_id) REFERENCES permission (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create user_role junction table
        $this->addSql('CREATE TABLE user_role (
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            PRIMARY KEY(user_id, role_id),
            INDEX IDX_2DE8C6A3A76ED395 (user_id),
            INDEX IDX_2DE8C6A3D60322AC (role_id),
            CONSTRAINT FK_2DE8C6A3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
            CONSTRAINT FK_2DE8C6A3D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Seed system roles
        $now = date('Y-m-d H:i:s');
        $this->addSql("INSERT INTO role (name, display_name, description, is_system, created_at, updated_at) VALUES
            ('ROLE_ADMIN', 'Administrator', 'Full system access with all permissions', 1, '{$now}', '{$now}'),
            ('ROLE_USER', 'User', 'Standard user with basic permissions', 1, '{$now}', '{$now}')
        ");

        // Seed core permissions - Dashboard
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_dashboard', 'Access Dashboard', 'Access main dashboard', 'dashboard', 1, NULL, '{$now}', '{$now}'),
            ('access_admin_overview', 'Access Admin Overview', 'Access admin overview page with system statistics (admin)', 'dashboard', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - User Management
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_users', 'Access Users', 'View and manage users list (admin)', 'user_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('create_user', 'Create User', 'Create new user accounts (admin)', 'user_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_user', 'Edit User', 'Edit user account details and roles (admin)', 'user_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('delete_user', 'Delete User', 'Delete user accounts from the system (admin)', 'user_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_user', 'View User Details', 'View detailed user information (admin)', 'user_management (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Server Management
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_servers', 'Access Servers', 'View and manage all servers (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_server', 'Edit Server', 'Edit server configurations and settings (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('delete_server', 'Delete Server', 'Delete servers from the system (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_server', 'View Server', 'View detailed server information (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_server_logs', 'Access Server Logs', 'View server activity logs (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_server_products', 'Access Server Products', 'View and manage server products (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_server_product', 'View Server Product', 'View server product details (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_server_product', 'Edit Server Product', 'Edit server product configuration (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('delete_server_product', 'Delete Server Product', 'Delete server products (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Shop
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_shop', 'Access Shop', 'Browse shop and view products', 'shop', 1, NULL, '{$now}', '{$now}'),
            ('access_categories', 'Access Categories', 'View and manage product categories (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('create_category', 'Create Category', 'Create new product categories (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_category', 'Edit Category', 'Edit existing categories (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('delete_category', 'Delete Category', 'Delete product categories (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_category', 'View Category', 'View category details (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_products', 'Access Products', 'View and manage products (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('create_product', 'Create Product', 'Create new products in the shop (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_product', 'Edit Product', 'Edit product details and pricing (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('delete_product', 'Delete Product', 'Delete products from the shop (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_product', 'View Product', 'View product details (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}'),
            ('copy_product', 'Copy Product', 'Duplicate product to create a new one (admin)', 'shop (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Payment
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_wallet', 'Access Wallet', 'Access wallet and recharge balance', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('access_payments', 'Access Payments', 'View all payments and transactions (admin)', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('view_payment', 'View Payment Details', 'View payment transaction details (admin)', 'user_features', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Voucher
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_vouchers', 'Access Vouchers', 'View and manage vouchers (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}'),
            ('create_voucher', 'Create Voucher', 'Create new discount vouchers (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_voucher', 'Edit Voucher', 'Edit existing vouchers (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}'),
            ('delete_voucher', 'Delete Voucher', 'Delete vouchers from the system (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_voucher', 'View Voucher', 'View voucher details and usage (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_voucher_usages', 'Access Voucher Usages', 'View voucher redemption history (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_voucher_usage', 'View Voucher Usage', 'View specific voucher usage details (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}'),
            ('show_voucher_usages', 'Show Voucher Usages', 'View list of redeemed vouchers (admin)', 'voucher (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Logs
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_system_logs', 'Access System Logs', 'View system logs and events (admin)', 'logs (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_email_logs', 'Access Email Logs', 'View email delivery logs (admin)', 'logs (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_logs', 'Access Logs', 'View system logs list (admin)', 'logs (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_log', 'View Log', 'View detailed log entry information (admin)', 'logs (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_email_log', 'View Email Log', 'View email log entry details (admin)', 'logs (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_server_log', 'View Server Log', 'View server activity log details (admin)', 'logs (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Settings
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_settings_general', 'Access General Settings', 'View and edit general system settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_settings_pterodactyl', 'Access Pterodactyl Settings', 'View and edit Pterodactyl integration settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_settings_security', 'Access Security Settings', 'View and edit security settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_settings_payment', 'Access Payment Settings', 'View and edit payment gateway settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_settings_email', 'Access Email Settings', 'View and edit email/SMTP settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_settings_theme', 'Access Theme Settings', 'View and edit theme/appearance settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_settings_plugin', 'Access Plugin Settings', 'View and configure plugin settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Settings (Edit)
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('edit_settings_general', 'Edit General Settings', 'Edit general system configuration (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_settings_pterodactyl', 'Edit Pterodactyl Settings', 'Edit Pterodactyl API configuration (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_settings_security', 'Edit Security Settings', 'Edit security and authentication settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_settings_payment', 'Edit Payment Settings', 'Edit payment gateway configuration (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_settings_email', 'Edit Email Settings', 'Edit email/SMTP configuration (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_settings_theme', 'Edit Theme Settings', 'Edit theme and appearance configuration (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_settings_plugin', 'Edit Plugin Settings', 'Edit plugin-specific settings (admin)', 'settings (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Plugins
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_plugins', 'Access Plugins', 'View and manage plugins (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_plugin', 'View Plugin', 'View plugin information and settings (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}'),
            ('enable_plugin', 'Enable Plugin', 'Enable plugins and activate features (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}'),
            ('disable_plugin', 'Disable Plugin', 'Disable plugins and deactivate features (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}'),
            ('install_plugin', 'Install Plugin', 'Install new plugins to the system (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}'),
            ('uninstall_plugin', 'Uninstall Plugin', 'Remove plugins from the system (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}'),
            ('upload_plugin', 'Upload Plugin', 'Upload plugin packages for installation (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}'),
            ('configure_plugin', 'Configure Plugin', 'Configure plugin settings and options (admin)', 'plugins (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Role Management
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_roles', 'Access Roles', 'View and manage roles (admin)', 'role_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('create_role', 'Create Role', 'Create new custom roles (admin)', 'role_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('edit_role', 'Edit Role', 'Edit role permissions and details (admin)', 'role_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('delete_role', 'Delete Role', 'Delete custom roles from the system (admin)', 'role_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_role', 'View Role Details', 'View role details and assigned permissions (admin)', 'role_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('access_permissions', 'Access Permissions', 'View system permissions list (admin)', 'role_management (admin)', 1, NULL, '{$now}', '{$now}'),
            ('view_permission', 'View Permission Details', 'View permission details and description (admin)', 'role_management (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - User Features
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('access_my_account', 'Access My Account', 'Access own account settings and profile', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('access_my_servers', 'Access My Servers', 'View and manage own servers', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('access_user_payments', 'Access User Payments', 'View own payment history', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('view_user_payment', 'View User Payment', 'View own payment transaction details', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('edit_user_account', 'Edit User Account', 'Edit own account profile and settings', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('continue_payment', 'Continue Payment', 'Complete pending payment transactions', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('purchase_server', 'Purchase Server', 'Purchase new servers from the shop', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('renew_server', 'Renew Server', 'Renew server subscriptions', 'user_features', 1, NULL, '{$now}', '{$now}'),
            ('access_pterodactyl_sso', 'Access Pterodactyl SSO', 'Single sign-on to Pterodactyl panel', 'user_features', 1, NULL, '{$now}', '{$now}')
        ");

        // Seed core permissions - Pterodactyl Integration
        $this->addSql("INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at) VALUES
            ('pterodactyl_root_admin', 'Pterodactyl Root Admin', 'Grant root admin access in Pterodactyl Panel (admin, dangerous)', 'pterodactyl_integration (admin)', 1, NULL, '{$now}', '{$now}')
        ");

        // Assign ALL permissions to admin role
        $this->addSql("
            INSERT INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_ADMIN'
        ");

        // Assign user permissions to user role
        $this->addSql("
            INSERT INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_USER'
            AND p.code IN (
                'access_dashboard',
                'access_shop',
                'access_wallet',
                'access_my_account',
                'access_my_servers',
                'access_user_payments',
                'view_user_payment',
                'edit_user_account',
                'continue_payment',
                'purchase_server',
                'renew_server',
                'access_pterodactyl_sso'
            )
        ");

        // Migrate existing users to new role system
        // Users with ROLE_ADMIN in JSON -> admin role
        $this->addSql("
            INSERT INTO user_role (user_id, role_id)
            SELECT u.id, r.id
            FROM user u
            CROSS JOIN role r
            WHERE r.name = 'ROLE_ADMIN'
            AND JSON_CONTAINS(u.roles, '\"ROLE_ADMIN\"', '$')
        ");

        // All other users -> user role
        $this->addSql("
            INSERT INTO user_role (user_id, role_id)
            SELECT u.id, r.id
            FROM user u
            CROSS JOIN role r
            WHERE r.name = 'ROLE_USER'
            AND NOT JSON_CONTAINS(u.roles, '\"ROLE_ADMIN\"', '$')
        ");
    }

    public function down(Schema $schema): void
    {
        // Drop junction tables first (foreign keys)
        $this->addSql('DROP TABLE user_role');
        $this->addSql('DROP TABLE role_permission');

        // Drop main tables
        $this->addSql('DROP TABLE permission');
        $this->addSql('DROP TABLE role');
    }
}
