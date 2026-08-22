<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260807193430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add filter_local_user_urls global setting, enabled by default.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE settings ADD COLUMN IF NOT EXISTS filter_local_user_urls TINYINT NOT NULL DEFAULT 1'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE settings DROP COLUMN IF EXISTS filter_local_user_urls');
    }
}
