<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260318130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Podcast RSS import strategy and optional per-podcast cron schedule.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE podcast ADD import_strategy VARCHAR(32) NOT NULL DEFAULT 'backfill_all'");
        $this->addSql('ALTER TABLE podcast ADD import_cron VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast DROP import_cron');
        $this->addSql('ALTER TABLE podcast DROP import_strategy');
    }
}
