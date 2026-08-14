<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Smart Block: add sort order and allow-repeated-tracks options.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlists
                ADD smart_block_sort_order VARCHAR(20) DEFAULT 'random' NOT NULL,
                ADD smart_block_avoid_duplicates TINYINT(1) DEFAULT 1 NOT NULL
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlists
                DROP COLUMN smart_block_sort_order,
                DROP COLUMN smart_block_avoid_duplicates
                SQL
        );
    }
}
