<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260202164600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds upload_theme, copy_theme, export_theme and delete_theme permissions for theme management functionality';
    }

    public function up(Schema $schema): void
    {
        $now = date('Y-m-d H:i:s');

        // Add upload_theme permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('upload_theme', 'Upload Theme', 'Upload new theme packages via ZIP files', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Add copy_theme permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('copy_theme', 'Copy Theme', 'Copy existing themes to create new variations', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Add export_theme permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('export_theme', 'Export Theme', 'Export themes as ZIP packages for backup or distribution', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Add delete_theme permission
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('delete_theme', 'Delete Theme', 'Delete theme packages from the system', 'themes', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Assign upload_theme permission to ROLE_ADMIN
        $this->addSql("
            INSERT IGNORE INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_ADMIN'
            AND p.code = 'upload_theme'
        ");

        // Assign copy_theme permission to ROLE_ADMIN
        $this->addSql("
            INSERT IGNORE INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_ADMIN'
            AND p.code = 'copy_theme'
        ");

        // Assign export_theme permission to ROLE_ADMIN
        $this->addSql("
            INSERT IGNORE INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_ADMIN'
            AND p.code = 'export_theme'
        ");

        // Assign delete_theme permission to ROLE_ADMIN
        $this->addSql("
            INSERT IGNORE INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_ADMIN'
            AND p.code = 'delete_theme'
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove upload_theme permission from role_permission
        $this->addSql("
            DELETE rp FROM role_permission rp
            INNER JOIN permission p ON rp.permission_id = p.id
            WHERE p.code = 'upload_theme'
        ");

        // Remove copy_theme permission from role_permission
        $this->addSql("
            DELETE rp FROM role_permission rp
            INNER JOIN permission p ON rp.permission_id = p.id
            WHERE p.code = 'copy_theme'
        ");

        // Remove export_theme permission from role_permission
        $this->addSql("
            DELETE rp FROM role_permission rp
            INNER JOIN permission p ON rp.permission_id = p.id
            WHERE p.code = 'export_theme'
        ");

        // Remove delete_theme permission from role_permission
        $this->addSql("
            DELETE rp FROM role_permission rp
            INNER JOIN permission p ON rp.permission_id = p.id
            WHERE p.code = 'delete_theme'
        ");

        // Remove upload_theme permission from permission table
        $this->addSql("DELETE FROM permission WHERE code = 'upload_theme'");

        // Remove copy_theme permission from permission table
        $this->addSql("DELETE FROM permission WHERE code = 'copy_theme'");

        // Remove export_theme permission from permission table
        $this->addSql("DELETE FROM permission WHERE code = 'export_theme'");

        // Remove delete_theme permission from permission table
        $this->addSql("DELETE FROM permission WHERE code = 'delete_theme'");
    }
}
