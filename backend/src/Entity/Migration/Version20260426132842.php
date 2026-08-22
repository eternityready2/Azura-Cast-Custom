<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260426132842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add request prevention settings to station and schedule while preserving existing playlist options.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station ADD COLUMN IF NOT EXISTS requests_only_via_playlists TINYINT NOT NULL DEFAULT 0'
        );
        $this->addSql(
            'ALTER TABLE station_schedules ADD COLUMN IF NOT EXISTS prevent_requests TINYINT NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station DROP COLUMN IF EXISTS requests_only_via_playlists');
        $this->addSql('ALTER TABLE station_schedules DROP COLUMN IF EXISTS prevent_requests');
    }
}
