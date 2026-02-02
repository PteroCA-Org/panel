<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260202150800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate theme settings from theme_settings to hidden_settings context';
    }

    public function up(Schema $schema): void
    {
        // Update context for theme selection settings
        $this->addSql("UPDATE setting SET context = 'hidden_settings' WHERE name IN ('panel_theme', 'landing_theme', 'email_theme')");

        // Update context for theme-related settings
        $this->addSql("UPDATE setting SET context = 'hidden_settings' WHERE name IN ('theme_default_light_mode_color', 'theme_default_dark_mode_color', 'theme_disable_dark_mode', 'theme_default_mode')");
    }

    public function down(Schema $schema): void
    {
        // Revert back to theme_settings
        $this->addSql("UPDATE setting SET context = 'theme_settings' WHERE name IN ('panel_theme', 'landing_theme', 'email_theme')");

        $this->addSql("UPDATE setting SET context = 'theme_settings' WHERE name IN ('theme_default_light_mode_color', 'theme_default_dark_mode_color', 'theme_disable_dark_mode', 'theme_default_mode')");
    }
}
