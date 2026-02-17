<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260217094054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add minimum_topup_amount setting to payment_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO setting (name, value, type, context, hierarchy, nullable)
            VALUES ('minimum_topup_amount', '1.00', 'number', 'payment_settings', 30, 0)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name = 'minimum_topup_amount'");
    }
}
