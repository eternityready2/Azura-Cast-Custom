<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20250316120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add podcast import from RSS: feed_url, auto_import_enabled, auto_keep_episodes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE podcast ADD feed_url VARCHAR(500) DEFAULT NULL, ADD auto_import_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD auto_keep_episodes SMALLINT NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast DROP feed_url, DROP auto_import_enabled, DROP auto_keep_episodes');
    }
}
