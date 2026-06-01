<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260529120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clock_wheel_events audit table for clock wheel scheduling decisions (PR11).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE clock_wheel_events (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                clock_wheel_id INT DEFAULT NULL,
                slot_id INT DEFAULT NULL,
                media_id INT DEFAULT NULL,
                event_kind VARCHAR(32) NOT NULL,
                fallback_reason VARCHAR(48) DEFAULT NULL,
                event_timestamp DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                expected_play_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                actual_play_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                drift_seconds SMALLINT DEFAULT NULL,
                anchor_type VARCHAR(32) DEFAULT NULL,
                separation_relaxed TINYINT(1) NOT NULL DEFAULT 0,
                burn_rate_warning TINYINT(1) NOT NULL DEFAULT 0,
                INDEX idx_cwe_station_timestamp (station_id, event_timestamp),
                INDEX idx_cwe_wheel_timestamp (clock_wheel_id, event_timestamp),
                PRIMARY KEY (id),
                CONSTRAINT fk_cwe_station
                    FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE,
                CONSTRAINT fk_cwe_clock_wheel
                    FOREIGN KEY (clock_wheel_id) REFERENCES station_clock_wheels (id) ON DELETE SET NULL,
                CONSTRAINT fk_cwe_slot
                    FOREIGN KEY (slot_id) REFERENCES station_clock_wheel_slots (id) ON DELETE SET NULL,
                CONSTRAINT fk_cwe_media
                    FOREIGN KEY (media_id) REFERENCES station_media (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE clock_wheel_events');
    }
}
