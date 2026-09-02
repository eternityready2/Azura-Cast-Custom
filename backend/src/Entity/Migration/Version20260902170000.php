<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260902170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill podcast.auto_import_enabled to match is_enabled for RSS-import podcasts, '
            . 'fixing rows where the value had drifted (silently disabling scheduled RSS sync while '
            . 'the podcast still appeared "Enabled" in the UI and worked via manual Sync Now).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE podcast SET auto_import_enabled = is_enabled WHERE source = 'import' AND auto_import_enabled != is_enabled"
        );
    }

    public function down(Schema $schema): void
    {
        // Not reversible: we don't know the prior (drifted) per-row value.
    }
}
