<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251220010323 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add priority field to product and category tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD priority INT NOT NULL DEFAULT 0 AFTER name');
        $this->addSql('ALTER TABLE product ADD priority INT NOT NULL DEFAULT 0 AFTER is_active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP priority');
        $this->addSql('ALTER TABLE product DROP priority');
    }
}
