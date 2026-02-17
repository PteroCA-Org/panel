<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add configurable date format settings
 */
final class Version20260217093259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add date_format, date_timezone, and date_show_timezone settings with options';
    }

    public function up(Schema $schema): void
    {
        // 1. Setting: date_format (SELECT)
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'date_format', 'Y-m-d H:i', 'select', 'general_settings', 16, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'date_format')
        ");

        // 2. Options for date_format (8 formats)
        $this->addSql("
            INSERT INTO setting_option (setting_name, option_key, option_value, sort_order, created_at, updated_at) VALUES
            ('date_format', '2026-02-17 14:30', 'Y-m-d H:i', 0, NOW(), NOW()),
            ('date_format', '2026-02-17 14:30:45', 'Y-m-d H:i:s', 1, NOW(), NOW()),
            ('date_format', '17.02.2026 14:30', 'd.m.Y H:i', 2, NOW(), NOW()),
            ('date_format', '17.02.2026 14:30:45', 'd.m.Y H:i:s', 3, NOW(), NOW()),
            ('date_format', '17/02/2026 14:30', 'd/m/Y H:i', 4, NOW(), NOW()),
            ('date_format', '02/17/2026 2:30 PM', 'm/d/Y g:i A', 5, NOW(), NOW()),
            ('date_format', 'Feb 17, 2026 2:30 PM', 'M d, Y g:i A', 6, NOW(), NOW()),
            ('date_format', 'Feb 17, 2026 14:30', 'M d, Y H:i', 7, NOW(), NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");

        // 3. Setting: date_timezone (SELECT)
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'date_timezone', 'UTC', 'select', 'general_settings', 17, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'date_timezone')
        ");

        // 4. Options for date_timezone (~25 zones)
        $this->addSql("
            INSERT INTO setting_option (setting_name, option_key, option_value, sort_order, created_at, updated_at) VALUES
            ('date_timezone', 'UTC (GMT+0:00)', 'UTC', 0, NOW(), NOW()),
            ('date_timezone', 'Europe/Warsaw (GMT+1:00/+2:00)', 'Europe/Warsaw', 1, NOW(), NOW()),
            ('date_timezone', 'Europe/London (GMT+0:00/+1:00)', 'Europe/London', 2, NOW(), NOW()),
            ('date_timezone', 'Europe/Berlin (GMT+1:00/+2:00)', 'Europe/Berlin', 3, NOW(), NOW()),
            ('date_timezone', 'Europe/Paris (GMT+1:00/+2:00)', 'Europe/Paris', 4, NOW(), NOW()),
            ('date_timezone', 'America/New_York (GMT-5:00/-4:00)', 'America/New_York', 5, NOW(), NOW()),
            ('date_timezone', 'America/Chicago (GMT-6:00/-5:00)', 'America/Chicago', 6, NOW(), NOW()),
            ('date_timezone', 'America/Los_Angeles (GMT-8:00/-7:00)', 'America/Los_Angeles', 7, NOW(), NOW()),
            ('date_timezone', 'America/Toronto (GMT-5:00/-4:00)', 'America/Toronto', 8, NOW(), NOW()),
            ('date_timezone', 'America/Sao_Paulo (GMT-3:00)', 'America/Sao_Paulo', 9, NOW(), NOW()),
            ('date_timezone', 'Asia/Tokyo (GMT+9:00)', 'Asia/Tokyo', 10, NOW(), NOW()),
            ('date_timezone', 'Asia/Shanghai (GMT+8:00)', 'Asia/Shanghai', 11, NOW(), NOW()),
            ('date_timezone', 'Asia/Hong_Kong (GMT+8:00)', 'Asia/Hong_Kong', 12, NOW(), NOW()),
            ('date_timezone', 'Asia/Singapore (GMT+8:00)', 'Asia/Singapore', 13, NOW(), NOW()),
            ('date_timezone', 'Asia/Dubai (GMT+4:00)', 'Asia/Dubai', 14, NOW(), NOW()),
            ('date_timezone', 'Asia/Kolkata (GMT+5:30)', 'Asia/Kolkata', 15, NOW(), NOW()),
            ('date_timezone', 'Australia/Sydney (GMT+10:00/+11:00)', 'Australia/Sydney', 16, NOW(), NOW()),
            ('date_timezone', 'Australia/Melbourne (GMT+10:00/+11:00)', 'Australia/Melbourne', 17, NOW(), NOW()),
            ('date_timezone', 'Pacific/Auckland (GMT+12:00/+13:00)', 'Pacific/Auckland', 18, NOW(), NOW()),
            ('date_timezone', 'Europe/Amsterdam (GMT+1:00/+2:00)', 'Europe/Amsterdam', 19, NOW(), NOW()),
            ('date_timezone', 'Europe/Moscow (GMT+3:00)', 'Europe/Moscow', 20, NOW(), NOW()),
            ('date_timezone', 'Africa/Cairo (GMT+2:00)', 'Africa/Cairo', 21, NOW(), NOW()),
            ('date_timezone', 'America/Mexico_City (GMT-6:00/-5:00)', 'America/Mexico_City', 22, NOW(), NOW()),
            ('date_timezone', 'America/Denver (GMT-7:00/-6:00)', 'America/Denver', 23, NOW(), NOW()),
            ('date_timezone', 'Pacific/Honolulu (GMT-10:00)', 'Pacific/Honolulu', 24, NOW(), NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");

        // 5. Setting: date_show_timezone (BOOLEAN)
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            SELECT 'date_show_timezone', '0', 'boolean', 'general_settings', 18, 0
            WHERE NOT EXISTS (SELECT 1 FROM setting WHERE name = 'date_show_timezone')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting_option WHERE setting_name IN ('date_format', 'date_timezone')");
        $this->addSql("DELETE FROM setting WHERE name IN ('date_format', 'date_timezone', 'date_show_timezone')");
    }
}
