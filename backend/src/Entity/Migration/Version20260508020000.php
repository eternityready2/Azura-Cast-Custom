<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260508020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create station_media_categories table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE IF NOT EXISTS station_media_categories (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
                PRIMARY KEY (id),
                INDEX idx_smc_station (station_id),
                CONSTRAINT fk_smc_station
                    FOREIGN KEY (station_id)
                    REFERENCES station (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS station_media_categories');
    }
}
