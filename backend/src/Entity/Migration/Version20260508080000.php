<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop obsolete slot rotation state columns from station_clock_wheels.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheels
                DROP COLUMN IF EXISTS last_slot_index,
                DROP COLUMN IF EXISTS last_slot_advanced_at'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheels
                ADD COLUMN IF NOT EXISTS last_slot_index INT DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS last_slot_advanced_at DATETIME DEFAULT NULL'
        );
    }
}
