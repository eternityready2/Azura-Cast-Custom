<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260510000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_rigid flag to clock wheel slots so top-of-hour anchors can preempt the rotation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheel_slots
                ADD COLUMN IF NOT EXISTS is_rigid TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheel_slots
                DROP COLUMN IF EXISTS is_rigid'
        );
    }
}
