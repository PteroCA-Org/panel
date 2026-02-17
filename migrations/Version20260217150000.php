<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shortDescription field to Product entity for SEO and listing previews';
    }

    public function up(Schema $schema): void
    {
        // Add short_description column as VARCHAR(255), nullable
        $this->addSql('ALTER TABLE product ADD short_description VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove short_description column if rollback is needed
        $this->addSql('ALTER TABLE product DROP short_description');
    }
}
