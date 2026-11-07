<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a security setting to enable or disable public registration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'public_registration_enabled', '1', 'boolean', 'security_settings', 49, 0
            WHERE NOT EXISTS (
                SELECT 1 FROM setting
                WHERE name = 'public_registration_enabled' AND context = 'security_settings'
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name = 'public_registration_enabled' AND context = 'security_settings'");
    }
}
