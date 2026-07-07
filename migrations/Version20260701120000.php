<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Core\Enum\SettingEnum;
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
        $this->addSql(
            'INSERT INTO setting (name, value, type, context, hierarchy, nullable) VALUES (?, ?, ?, ?, ?, ?)',
            [
                SettingEnum::LANDING_FEATURED_CATEGORIES_COUNT->value,
                '6',
                'number',
                'general_settings',
                130,
                0
            ]
        );

        $this->addSql(
            'INSERT INTO setting (name, value, type, context, hierarchy, nullable) VALUES (?, ?, ?, ?, ?, ?)',
            [
                SettingEnum::LANDING_FEATURED_PRODUCTS_COUNT->value,
                '6',
                'number',
                'general_settings',
                131,
                0
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM setting WHERE name = ?',
            [SettingEnum::LANDING_FEATURED_CATEGORIES_COUNT->value]
        );

        $this->addSql(
            'DELETE FROM setting WHERE name = ?',
            [SettingEnum::LANDING_FEATURED_PRODUCTS_COUNT->value]
        );
    }
}
