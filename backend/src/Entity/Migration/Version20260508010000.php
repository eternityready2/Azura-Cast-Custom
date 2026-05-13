<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260508010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add type column to station_media for Clock Wheel category-based scheduling.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE station_media
                ADD COLUMN IF NOT EXISTS `type` VARCHAR(20) NOT NULL DEFAULT 'music'
                AFTER isrc"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_media DROP COLUMN `type`');
    }
}
