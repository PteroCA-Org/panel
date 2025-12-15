<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251215175124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique constraint on (name, context) to setting table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            DELETE s1 FROM setting s1
            INNER JOIN setting s2
            WHERE s1.name = s2.name
            AND s1.context = s2.context
            AND s1.id > s2.id
        ');

        $this->addSql('ALTER TABLE setting ADD UNIQUE KEY unique_setting_name_context (name, context)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setting DROP INDEX unique_setting_name_context');
    }
}
