<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add settings for number of featured categories and featured products shown on the landing page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'landing_featured_categories_count', '6', 'number', 'general_settings', 130, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'landing_featured_categories_count')
        ");

        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'landing_featured_products_count', '6', 'number', 'general_settings', 131, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'landing_featured_products_count')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name = 'landing_featured_categories_count'");
        $this->addSql("DELETE FROM setting WHERE name = 'landing_featured_products_count'");
    }
}
