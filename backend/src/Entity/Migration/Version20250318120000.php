<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20250318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add podcast media_folder_path for custom media subfolder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast ADD media_folder_path VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcast DROP media_folder_path');
    }
}
