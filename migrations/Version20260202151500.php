<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260202151500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds theme management permissions (access_themes, view_theme, set_default_theme, configure_theme)';
    }

    public function up(Schema $schema): void
    {
        $now = date('Y-m-d H:i:s');

        // Add access_themes permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('access_themes', 'Access Themes', 'Access theme management section', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Add view_theme permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('view_theme', 'View Theme', 'View theme details and information', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Add set_default_theme permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('set_default_theme', 'Set Default Theme', 'Change default theme for panel/landing/email contexts', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Add configure_theme permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('configure_theme', 'Configure Theme', 'Configure theme settings and options', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Assign all theme permissions to ROLE_ADMIN
        $this->addSql("
            INSERT IGNORE INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_ADMIN'
            AND p.code IN ('access_themes', 'view_theme', 'set_default_theme', 'configure_theme')
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove theme permissions from role_permission
        $this->addSql("
            DELETE rp FROM role_permission rp
            INNER JOIN permission p ON rp.permission_id = p.id
            WHERE p.code IN ('access_themes', 'view_theme', 'set_default_theme', 'configure_theme')
        ");

        // Remove theme permissions from permission table
        $this->addSql("DELETE FROM permission WHERE code IN ('access_themes', 'view_theme', 'set_default_theme', 'configure_theme')");
    }
}
