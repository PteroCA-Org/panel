<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Core\Enum\SettingEnum;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom head scripts settings for landing and panel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO setting (name, value, type, context, hierarchy, nullable) VALUES (?, ?, ?, ?, ?, ?)',
            [
                SettingEnum::CUSTOM_HEAD_SCRIPTS_LANDING->value,
                null,
                'textarea',
                'general_settings',
                120,
                1
            ]
        );

        $this->addSql(
            'INSERT INTO setting (name, value, type, context, hierarchy, nullable) VALUES (?, ?, ?, ?, ?, ?)',
            [
                SettingEnum::CUSTOM_HEAD_SCRIPTS_PANEL->value,
                null,
                'textarea',
                'general_settings',
                121,
                1
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM setting WHERE name = ?',
            [SettingEnum::CUSTOM_HEAD_SCRIPTS_LANDING->value]
        );

        $this->addSql(
            'DELETE FROM setting WHERE name = ?',
            [SettingEnum::CUSTOM_HEAD_SCRIPTS_PANEL->value]
        );
    }
}
