<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260321120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Podcast episodes: store remote enclosure URL for RSS when audio is not hosted locally.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE podcast_episode ADD remote_enclosure_url VARCHAR(2048) DEFAULT NULL, ADD remote_enclosure_mime VARCHAR(127) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast_episode DROP remote_enclosure_url, DROP remote_enclosure_mime');
    }
}
