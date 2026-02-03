<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260104152100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add location selection feature with product variants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD allow_user_select_location TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE server_product ADD allow_user_select_location TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE server_product ADD selected_node_id INT DEFAULT NULL');

        $this->addSql('CREATE TABLE product_variant (
            product_id INT NOT NULL,
            variant_product_id INT NOT NULL,
            INDEX IDX_PRODUCT (product_id),
            INDEX IDX_VARIANT (variant_product_id),
            PRIMARY KEY(product_id, variant_product_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE product_variant
            ADD CONSTRAINT FK_PRODUCT_VARIANT_PRODUCT
            FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE product_variant
            ADD CONSTRAINT FK_PRODUCT_VARIANT_VARIANT
            FOREIGN KEY (variant_product_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_variant');
        $this->addSql('ALTER TABLE server_product DROP selected_node_id');
        $this->addSql('ALTER TABLE server_product DROP allow_user_select_location');
        $this->addSql('ALTER TABLE product DROP allow_user_select_location');
    }
}
