<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251228203004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change smtp_password setting type from text to secret for security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE setting SET type = 'secret' WHERE name = 'smtp_password'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE setting SET type = 'text' WHERE name = 'smtp_password'");
    }
}
