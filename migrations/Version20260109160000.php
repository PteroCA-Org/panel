<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260109160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds context-based theme settings (panel_theme, landing_theme, email_theme) and landing_page_enabled setting';
    }

    public function up(Schema $schema): void
    {
        // Create panel_theme based on current_theme
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'panel_theme', value, 'select', 'theme_settings', 100, 0
            FROM setting
            WHERE name = 'current_theme'
            AND NOT EXISTS (SELECT 1 FROM setting s WHERE s.name = 'panel_theme')
        ");

        // Create landing_theme (default 'default')
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'landing_theme', 'default', 'select', 'theme_settings', 101, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'landing_theme')
        ");

        // Create email_theme (default 'default')
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'email_theme', 'default', 'select', 'theme_settings', 102, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'email_theme')
        ");

        // Create landing_page_enabled (default true)
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'landing_page_enabled', '1', 'boolean', 'general_settings', 50, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'landing_page_enabled')
        ");

        // Mark current_theme as legacy (for backward compatibility)
        $this->addSql("
            UPDATE setting
            SET context = 'theme_settings.legacy'
            WHERE name = 'current_theme' AND context = 'theme_settings'
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove new settings
        $this->addSql("DELETE FROM setting WHERE name = 'panel_theme'");
        $this->addSql("DELETE FROM setting WHERE name = 'landing_theme'");
        $this->addSql("DELETE FROM setting WHERE name = 'email_theme'");
        $this->addSql("DELETE FROM setting WHERE name = 'landing_page_enabled'");

        // Restore current_theme context
        $this->addSql("
            UPDATE setting
            SET context = 'theme_settings'
            WHERE name = 'current_theme' AND context = 'theme_settings.legacy'
        ");
    }
}
