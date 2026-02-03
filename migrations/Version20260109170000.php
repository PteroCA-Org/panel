<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260109170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds featured field to Product and Category entities for landing page featured items';
    }

    public function up(Schema $schema): void
    {
        // Add featured column to product table
        $this->addSql('ALTER TABLE product ADD featured TINYINT(1) DEFAULT 0 NOT NULL');

        // Add featured column to category table
        $this->addSql('ALTER TABLE category ADD featured TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove featured column from product table
        $this->addSql('ALTER TABLE product DROP featured');

        // Remove featured column from category table
        $this->addSql('ALTER TABLE category DROP featured');
    }
}
