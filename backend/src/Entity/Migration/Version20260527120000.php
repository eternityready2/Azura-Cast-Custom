<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260527120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clock_wheel_id to station_queue and song_history for playback source metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
                ADD COLUMN IF NOT EXISTS clock_wheel_id INT DEFAULT NULL,
                ADD CONSTRAINT FK_station_queue_clock_wheel
                    FOREIGN KEY IF NOT EXISTS (clock_wheel_id)
                    REFERENCES station_clock_wheels (id)
                    ON DELETE SET NULL'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS IDX_station_queue_clock_wheel ON station_queue (clock_wheel_id)'
        );

        $this->addSql(
            'ALTER TABLE song_history
                ADD COLUMN IF NOT EXISTS clock_wheel_id INT DEFAULT NULL,
                ADD CONSTRAINT FK_song_history_clock_wheel
                    FOREIGN KEY IF NOT EXISTS (clock_wheel_id)
                    REFERENCES station_clock_wheels (id)
                    ON DELETE SET NULL'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS IDX_song_history_clock_wheel ON song_history (clock_wheel_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_queue DROP FOREIGN KEY IF EXISTS FK_station_queue_clock_wheel');
        $this->addSql('DROP INDEX IF EXISTS IDX_station_queue_clock_wheel ON station_queue');
        $this->addSql('ALTER TABLE station_queue DROP COLUMN IF EXISTS clock_wheel_id');

        $this->addSql('ALTER TABLE song_history DROP FOREIGN KEY IF EXISTS FK_song_history_clock_wheel');
        $this->addSql('DROP INDEX IF EXISTS IDX_song_history_clock_wheel ON song_history');
        $this->addSql('ALTER TABLE song_history DROP COLUMN IF EXISTS clock_wheel_id');
    }
}
