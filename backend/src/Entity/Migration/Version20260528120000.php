<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260528120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clock wheel schedule mode and queue playback cap fields for PR8 timing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE station_schedules
                ADD COLUMN IF NOT EXISTS clock_wheel_mode VARCHAR(20) DEFAULT NULL"
        );

        $this->addSql(
            'ALTER TABLE station_queue
                ADD COLUMN IF NOT EXISTS clock_wheel_max_play_seconds INT DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS clock_wheel_schedule_mode VARCHAR(20) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS clock_wheel_enforce_cap TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_schedules DROP COLUMN IF EXISTS clock_wheel_mode');
        $this->addSql(
            'ALTER TABLE station_queue
                DROP COLUMN IF EXISTS clock_wheel_max_play_seconds,
                DROP COLUMN IF EXISTS clock_wheel_schedule_mode,
                DROP COLUMN IF EXISTS clock_wheel_enforce_cap'
        );
    }
}
