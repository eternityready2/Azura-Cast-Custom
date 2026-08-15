<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add minimal sequential playlist groups.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists
             ADD COLUMN group_next_position SMALLINT UNSIGNED NOT NULL DEFAULT 0'
        );
        $this->addSql(
            'CREATE TABLE station_playlist_group_members (
                id INT AUTO_INCREMENT NOT NULL,
                group_id INT NOT NULL,
                playlist_id INT NOT NULL,
                position SMALLINT UNSIGNED NOT NULL,
                INDEX IDX_playlist_group (group_id),
                INDEX IDX_playlist_group_child (playlist_id),
                UNIQUE INDEX uniq_playlist_group_position (group_id, position),
                PRIMARY KEY(id)
             ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE station_playlist_group_members
             ADD CONSTRAINT FK_playlist_group
             FOREIGN KEY (group_id) REFERENCES station_playlists (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE station_playlist_group_members
             ADD CONSTRAINT FK_playlist_group_child
             FOREIGN KEY (playlist_id) REFERENCES station_playlists (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE station_playlist_group_members');
        $this->addSql('ALTER TABLE station_playlists DROP COLUMN group_next_position');
    }
}
