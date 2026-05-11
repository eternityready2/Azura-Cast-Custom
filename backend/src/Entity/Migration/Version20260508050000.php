<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make station_clock_wheel_slots.type nullable (category-only slots).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheel_slots MODIFY COLUMN type VARCHAR(20) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE station_clock_wheel_slots SET type = 'music' WHERE type IS NULL"
        );
        $this->addSql(
            "ALTER TABLE station_clock_wheel_slots MODIFY COLUMN type VARCHAR(20) NOT NULL DEFAULT 'music'"
        );
    }
}
