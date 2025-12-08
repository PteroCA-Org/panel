<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251207161731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telemetry consent setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable) VALUES
            ('telemetry_consent', '1', 'boolean', 'general_settings', 20, 0)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name = 'telemetry_consent'");
    }
}
