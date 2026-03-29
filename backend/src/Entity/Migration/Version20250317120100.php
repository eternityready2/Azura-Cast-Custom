<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20250317120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add podcast episode_storage_type to route episodes to media or podcast folder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE podcast ADD episode_storage_type VARCHAR(50) DEFAULT 'podcast' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast DROP episode_storage_type');
    }
}
