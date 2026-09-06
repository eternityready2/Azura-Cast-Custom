<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260906170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give automatic Top-of-Hour Station IDs explicit per-hour ownership.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
                ADD top_of_hour_boundary_at DATETIME(6) DEFAULT NULL'
        );
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_station_toh_boundary
                ON station_queue (station_id, top_of_hour_boundary_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_station_toh_boundary ON station_queue');
        $this->addSql('ALTER TABLE station_queue DROP top_of_hour_boundary_at');
    }
}
