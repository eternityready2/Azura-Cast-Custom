<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Smart Blocks: criteria-based dynamic playlists that automatically ' .
            'keep their membership in sync with the media library.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlists
                ADD is_smart_block TINYINT(1) DEFAULT 0 NOT NULL,
                ADD smart_block_match_type VARCHAR(10) DEFAULT 'all' NOT NULL,
                ADD smart_block_limit INT DEFAULT NULL,
                ADD smart_block_limit_type VARCHAR(10) DEFAULT 'tracks' NOT NULL,
                ADD smart_block_type VARCHAR(10) DEFAULT 'dynamic' NOT NULL
                SQL
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS station_playlist_smart_block_criteria (
                    id INT AUTO_INCREMENT NOT NULL,
                    playlist_id INT NOT NULL,
                    custom_field_id INT DEFAULT NULL,
                    field VARCHAR(25) NOT NULL,
                    comparison VARCHAR(25) NOT NULL,
                    value VARCHAR(255) DEFAULT NULL,
                    value2 VARCHAR(255) DEFAULT NULL,
                    weight INT NOT NULL,
                    INDEX IDX_station_playlist_smart_block_criteria_playlist_id (playlist_id),
                    INDEX IDX_station_playlist_smart_block_criteria_custom_field_id (custom_field_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_smart_block_criteria
                ADD CONSTRAINT FK_station_playlist_smart_block_criteria_playlist_id
                FOREIGN KEY IF NOT EXISTS (playlist_id)
                REFERENCES station_playlists (id)
                ON DELETE CASCADE
                SQL
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_smart_block_criteria
                ADD CONSTRAINT FK_station_playlist_smart_block_criteria_custom_field_id
                FOREIGN KEY IF NOT EXISTS (custom_field_id)
                REFERENCES custom_field (id)
                ON DELETE CASCADE
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_smart_block_criteria
                DROP FOREIGN KEY IF EXISTS FK_station_playlist_smart_block_criteria_playlist_id
                SQL
        );
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_smart_block_criteria
                DROP FOREIGN KEY IF EXISTS FK_station_playlist_smart_block_criteria_custom_field_id
                SQL
        );
        $this->addSql('DROP TABLE IF EXISTS station_playlist_smart_block_criteria');

        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlists
                DROP COLUMN is_smart_block,
                DROP COLUMN smart_block_match_type,
                DROP COLUMN smart_block_limit,
                DROP COLUMN smart_block_limit_type,
                DROP COLUMN smart_block_type
                SQL
        );
    }
}
