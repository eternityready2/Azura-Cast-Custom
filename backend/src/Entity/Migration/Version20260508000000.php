<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260508000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clock_wheel_id to station_schedules; drop orphaned station_clock_wheel_events table.';
    }

    public function up(Schema $schema): void
    {
        // Add clock_wheel_id to station_schedules (links a schedule to a Clock Wheel instead of a Playlist)
        $this->addSql(
            'ALTER TABLE station_schedules
                ADD COLUMN IF NOT EXISTS clock_wheel_id INT DEFAULT NULL,
                ADD CONSTRAINT FK_B3BFB2955D856AB6
                    FOREIGN KEY IF NOT EXISTS (clock_wheel_id)
                    REFERENCES station_clock_wheels (id)
                    ON DELETE SET NULL'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS IDX_B3BFB2955D856AB6 ON station_schedules (clock_wheel_id)'
        );

        // Drop the old station_clock_wheel_events table (replaced by station_schedules.clock_wheel_id)
        $db = $this->connection->getDatabase();
        $hasTable = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'station_clock_wheel_events'",
            [$db]
        );
        if ($hasTable > 0) {
            $this->addSql('ALTER TABLE station_clock_wheel_events DROP FOREIGN KEY IF EXISTS `fk_cwe_clock_wheel`');
            $this->addSql('DROP TABLE station_clock_wheel_events');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_schedules DROP FOREIGN KEY FK_B3BFB2955D856AB6');
        $this->addSql('DROP INDEX IDX_B3BFB2955D856AB6 ON station_schedules');
        $this->addSql('ALTER TABLE station_schedules DROP COLUMN clock_wheel_id');

        $this->addSql(
            'CREATE TABLE station_clock_wheel_events (
                id INT AUTO_INCREMENT NOT NULL,
                clock_wheel_id INT NOT NULL,
                start_time SMALLINT NOT NULL,
                end_time SMALLINT NOT NULL,
                days VARCHAR(50) DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_clock_wheel (clock_wheel_id),
                CONSTRAINT fk_cwe_clock_wheel
                    FOREIGN KEY (clock_wheel_id)
                    REFERENCES station_clock_wheels (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
    }
}
