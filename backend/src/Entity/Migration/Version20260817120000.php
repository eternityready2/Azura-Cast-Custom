<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add top_of_hour_pre_id_fade and top_of_hour_pre_id_fade_seconds to station_queue '
            . 'for dedicated early fade-out ahead of the mandatory top-of-hour ID.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
                ADD COLUMN IF NOT EXISTS top_of_hour_pre_id_fade TINYINT(1) NOT NULL DEFAULT 0'
        );
        $this->addSql(
            'ALTER TABLE station_queue
                ADD COLUMN IF NOT EXISTS top_of_hour_pre_id_fade_seconds INT DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_queue DROP COLUMN IF EXISTS top_of_hour_pre_id_fade');
        $this->addSql('ALTER TABLE station_queue DROP COLUMN IF EXISTS top_of_hour_pre_id_fade_seconds');
    }
}
