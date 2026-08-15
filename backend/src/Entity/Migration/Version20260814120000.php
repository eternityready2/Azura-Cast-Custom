<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the originating Group List on queued member tracks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
             ADD COLUMN group_playlist_id INT DEFAULT NULL,
             ADD INDEX IDX_station_queue_group_playlist (group_playlist_id),
             ADD CONSTRAINT FK_station_queue_group_playlist
                 FOREIGN KEY (group_playlist_id) REFERENCES station_playlists (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
             DROP FOREIGN KEY FK_station_queue_group_playlist,
             DROP INDEX IDX_station_queue_group_playlist,
             DROP COLUMN group_playlist_id'
        );
    }
}
