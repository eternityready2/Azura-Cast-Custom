<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260507120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add station_clock_wheels and station_clock_wheel_slots tables for the Clock Wheel module.';
    }

    public function up(Schema $schema): void
    {
        // Parent table: one clock wheel per station with a name and colour swatch.
        $this->addSql(
            'CREATE TABLE station_clock_wheels (
                id          INT AUTO_INCREMENT NOT NULL,
                station_id  INT NOT NULL,
                name        VARCHAR(100) NOT NULL,
                color       VARCHAR(7) NOT NULL DEFAULT \'#e87722\',
                is_active   TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_scw_station (station_id),
                CONSTRAINT fk_scw_station
                    FOREIGN KEY (station_id)
                    REFERENCES station (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );

        // Child table: ordered content slots that make up each wheel.
        $this->addSql(
            'CREATE TABLE station_clock_wheel_slots (
                id              INT AUTO_INCREMENT NOT NULL,
                clock_wheel_id  INT NOT NULL,
                playlist_id     INT DEFAULT NULL,
                type            VARCHAR(20) NOT NULL DEFAULT \'music\',
                algorithm       VARCHAR(30) NOT NULL DEFAULT \'random\',
                slot_order      SMALLINT NOT NULL DEFAULT 0,
                duration_seconds SMALLINT DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_scws_wheel (clock_wheel_id),
                INDEX idx_scws_playlist (playlist_id),
                CONSTRAINT fk_scws_wheel
                    FOREIGN KEY (clock_wheel_id)
                    REFERENCES station_clock_wheels (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_scws_playlist
                    FOREIGN KEY (playlist_id)
                    REFERENCES station_playlists (id)
                    ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        // Drop child first to avoid FK constraint errors.
        $this->addSql('DROP TABLE station_clock_wheel_slots');
        $this->addSql('DROP TABLE station_clock_wheels');
    }
}
