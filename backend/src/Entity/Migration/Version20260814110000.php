<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-occurrence playback modes to playlist group members.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlist_group_members
             ADD COLUMN consecutive_plays SMALLINT UNSIGNED NOT NULL DEFAULT 1,
             ADD COLUMN play_full_cycle TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlist_group_members
             DROP COLUMN consecutive_plays,
             DROP COLUMN play_full_cycle'
        );
    }
}
