<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260318150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove podcast import_cron column (replaced by import_sync_before_hours).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast DROP import_cron');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast ADD import_cron VARCHAR(120) DEFAULT NULL');
    }
}
