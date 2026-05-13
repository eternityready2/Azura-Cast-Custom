<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category_id FK to station_media.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_media
             ADD COLUMN IF NOT EXISTS category_id INT DEFAULT NULL,
             ADD CONSTRAINT fk_station_media_category
                 FOREIGN KEY (category_id)
                 REFERENCES station_media_categories (id)
                 ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_media
             DROP FOREIGN KEY IF EXISTS fk_station_media_category,
             DROP COLUMN IF EXISTS category_id'
        );
    }
}
