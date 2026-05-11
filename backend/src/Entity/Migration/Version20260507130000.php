<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260507130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add station_clock_wheel_events table for Clock Wheel calendar scheduling.';
    }

    public function up(Schema $schema): void
    {
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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE station_clock_wheel_events');
    }
}
