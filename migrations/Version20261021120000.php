<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Release unique codes of soft-deleted vouchers so they can be reused';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            UPDATE voucher
            SET code = CONCAT(LEFT(code, 200), '-deleted-', id)
            WHERE deleted_at IS NOT NULL
              AND code NOT LIKE CONCAT('%-deleted-', id)
        ");
    }

    public function down(Schema $schema): void
    {
        // Original codes cannot be restored safely because they may have been reused
    }
}
