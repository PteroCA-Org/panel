<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260202150800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hide theme selection settings (panel_theme, landing_theme, email_theme) from theme_settings context';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE setting SET context = 'hidden_settings' WHERE name IN ('panel_theme', 'landing_theme', 'email_theme')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE setting SET context = 'theme_settings' WHERE name IN ('panel_theme', 'landing_theme', 'email_theme')");
    }
}
