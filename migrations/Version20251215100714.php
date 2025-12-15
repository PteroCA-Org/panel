<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251215100714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update nullable field to true for site_logo and site_favicon settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE setting SET nullable = 1 WHERE name IN ('site_logo', 'site_favicon')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE setting SET nullable = 0 WHERE name IN ('site_logo', 'site_favicon')");
    }
}
