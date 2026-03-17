<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20250317120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove station_ai_djs table (AI DJ feature removed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS station_ai_djs');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE station_ai_djs (id INT AUTO_INCREMENT NOT NULL, station_id INT NOT NULL, name VARCHAR(255) NOT NULL, is_enabled TINYINT(1) NOT NULL, shift_morning TINYINT(1) NOT NULL, shift_afternoon TINYINT(1) NOT NULL, shift_overnight TINYINT(1) NOT NULL, duties_config JSON DEFAULT NULL, INDEX IDX_AI_DJS_STATION (station_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE station_ai_djs ADD CONSTRAINT FK_AI_DJS_STATION FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE'
        );
    }
}
