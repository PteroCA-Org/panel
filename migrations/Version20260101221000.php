<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds create_server permission for admin server creation functionality';
    }

    public function up(Schema $schema): void
    {
        $now = date('Y-m-d H:i:s');

        // Add create_server permission to the permission table
        $this->addSql("
            INSERT INTO permission (code, name, description, section, is_system, plugin_name, created_at, updated_at)
            VALUES ('create_server', 'Create Server', 'Create new servers for users from admin panel (admin)', 'server_management (admin)', 1, NULL, '{$now}', '{$now}')
            ON DUPLICATE KEY UPDATE updated_at = '{$now}'
        ");

        // Assign create_server permission to ROLE_ADMIN
        $this->addSql("
            INSERT IGNORE INTO role_permission (role_id, permission_id)
            SELECT r.id, p.id
            FROM role r
            CROSS JOIN permission p
            WHERE r.name = 'ROLE_ADMIN'
            AND p.code = 'create_server'
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove create_server permission from role_permission
        $this->addSql("
            DELETE rp FROM role_permission rp
            INNER JOIN permission p ON rp.permission_id = p.id
            WHERE p.code = 'create_server'
        ");

        // Remove create_server permission from permission table
        $this->addSql("DELETE FROM permission WHERE code = 'create_server'");
    }
}
