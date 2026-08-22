<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate custom playlist groups to upstream StationPlaylistGroup relations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE IF NOT EXISTS station_playlist_group (" .
            "playlist_id INT NOT NULL, " .
            "playlist_group_id INT NOT NULL, " .
            "weight INT NOT NULL DEFAULT 0, " .
            "is_queued TINYINT(1) NOT NULL DEFAULT 1, " .
            "last_played INT NOT NULL DEFAULT 0, " .
            "consecutive_plays INT NOT NULL DEFAULT 0, " .
            "consecutive_plays_count INT NOT NULL DEFAULT 0, " .
            "play_full_cycle TINYINT(1) NOT NULL DEFAULT 0, " .
            "allowed_requests VARCHAR(255) NOT NULL DEFAULT 'any', " .
            "id INT AUTO_INCREMENT NOT NULL, " .
            "INDEX IDX_ED9C32B06BBD148 (playlist_id), " .
            "INDEX IDX_ED9C32B03891F2A (playlist_group_id), " .
            "PRIMARY KEY(id)" .
            ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        $schemaManager = $this->connection->createSchemaManager();
        $tables = array_map('strtolower', $schemaManager->listTableNames());

        if (in_array('station_playlist_group_members', $tables, true)) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO station_playlist_group
                        (
                            playlist_id,
                            playlist_group_id,
                            weight,
                            is_queued,
                            last_played,
                            consecutive_plays,
                            consecutive_plays_count,
                            play_full_cycle,
                            allowed_requests
                        )
                    SELECT
                        old.playlist_id,
                        old.group_id,
                        old.position + 1,
                        1,
                        0,
                        CASE
                            WHEN old.consecutive_plays < 1 THEN 0
                            ELSE old.consecutive_plays
                        END,
                        0,
                        old.play_full_cycle,
                        'any'
                    FROM station_playlist_group_members old
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM station_playlist_group current_group
                        WHERE current_group.playlist_id = old.playlist_id
                          AND current_group.playlist_group_id = old.group_id
                          AND current_group.weight = old.position + 1
                    )
                SQL
            );
        }

        $this->addSql(
            "UPDATE station_playlists SET source = 'playlists' WHERE source = 'group'"
        );

        $this->addSql(
            'ALTER TABLE station_playlist_group ADD CONSTRAINT FK_ED9C32B06BBD148 ' .
            'FOREIGN KEY (playlist_id) REFERENCES station_playlists (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'ALTER TABLE station_playlist_group ADD CONSTRAINT FK_ED9C32B03891F2A ' .
            'FOREIGN KEY (playlist_group_id) REFERENCES station_playlists (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE station_playlists SET source = 'group' WHERE source = 'playlists'"
        );

        $this->addSql('DROP TABLE IF EXISTS station_playlist_group');
    }
}
