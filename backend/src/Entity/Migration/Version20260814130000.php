<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Convert existing Playlist Group rows from source='playlists' to source='group', "
            . 'matching the renamed enum case after the nested-group model replacement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE station_playlists SET source = 'group' WHERE source = 'playlists'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE station_playlists SET source = 'playlists' WHERE source = 'group'"
        );
    }
}
