<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20250316120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add station_ai_djs table for AI DJ (virtual host) feature.';
    }

    public function up(Schema $schema): void
    {
        // AI DJ feature removed: no longer create station_ai_djs table.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_ai_djs DROP FOREIGN KEY FK_AI_DJS_STATION');
        $this->addSql('DROP TABLE station_ai_djs');
    }
}
