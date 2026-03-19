<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260318140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Podcast import_sync_before_hours: sync once N hours before playlist schedule.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast ADD import_sync_before_hours SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast DROP import_sync_before_hours');
    }
}
