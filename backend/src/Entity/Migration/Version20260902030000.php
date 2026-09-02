<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260902030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the last successful Linear Log snapshot across restarts and deployments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS station_linear_log_snapshots (
      station_id INT NOT NULL,
      snapshot LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\',
      updated_at INT NOT NULL,
      PRIMARY KEY (station_id),
      CONSTRAINT fk_slls_station
          FOREIGN KEY (station_id)
          REFERENCES station (id)
          ON DELETE CASCADE
  ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE station_queue
             ADD COLUMN IF NOT EXISTS top_of_hour_expected_at DATETIME(6) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
             DROP COLUMN IF EXISTS top_of_hour_expected_at'
        );
        $this->addSql('DROP TABLE IF EXISTS station_linear_log_snapshots');
    }
}
