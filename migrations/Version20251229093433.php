<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251229093433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at field to category table for soft delete support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP deleted_at');
    }
}
