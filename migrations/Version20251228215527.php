<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251228215527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allowAutoRenewal field to product and server_product tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD allow_auto_renewal TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_change_egg');
        $this->addSql('ALTER TABLE server_product ADD allow_auto_renewal TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_change_egg');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP allow_auto_renewal');
        $this->addSql('ALTER TABLE server_product DROP allow_auto_renewal');
    }
}
