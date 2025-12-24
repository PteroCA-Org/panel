<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251224134652 extends AbstractMigration
{
    private const OLD_DEFAULT = '<h1>Terms of service</h1> <p>You can set content of this page in settings.</p>';
    private const NEW_DEFAULT = '<p>You can set content of this page in settings.</p>';

    public function getDescription(): string
    {
        return 'Update default terms of service content (remove h1 tag)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE setting SET value = ? WHERE name = ? AND value = ?',
            [self::NEW_DEFAULT, 'terms_of_service', self::OLD_DEFAULT]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE setting SET value = ? WHERE name = ? AND value = ?',
            [self::OLD_DEFAULT, 'terms_of_service', self::NEW_DEFAULT]
        );
    }
}
