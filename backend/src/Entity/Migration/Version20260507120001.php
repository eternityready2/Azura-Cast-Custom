<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260507120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align station_clock_wheels and station_clock_wheel_slots schema with current entity definitions.';
    }

    public function up(Schema $schema): void
    {
        // Add color if missing, shrink name — safe on any state
        $this->addSql("ALTER TABLE station_clock_wheels
            ADD COLUMN IF NOT EXISTS color VARCHAR(7) NOT NULL DEFAULT '#e87722' AFTER name,
            MODIFY COLUMN name VARCHAR(100) NOT NULL
        ");
        $this->addSql("ALTER TABLE station_clock_wheels DROP COLUMN IF EXISTS description");
        $this->addSql("ALTER TABLE station_clock_wheels DROP COLUMN IF EXISTS strict_timing");

        // Slots: drop legacy columns if they exist
        $this->addSql("ALTER TABLE station_clock_wheel_slots DROP COLUMN IF EXISTS label");
        $this->addSql("ALTER TABLE station_clock_wheel_slots DROP COLUMN IF EXISTS position_seconds");
        $this->addSql("ALTER TABLE station_clock_wheel_slots DROP COLUMN IF EXISTS weight");

        // Rename slot_type -> type only if slot_type still exists
        $db = $this->connection->getDatabase();
        $hasSlotType = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'station_clock_wheel_slots' AND COLUMN_NAME = 'slot_type'",
            [$db]
        );
        if ($hasSlotType > 0) {
            $this->addSql("ALTER TABLE station_clock_wheel_slots
                CHANGE COLUMN slot_type `type` VARCHAR(20) NOT NULL DEFAULT 'music'");
        } else {
            $this->addSql("ALTER TABLE station_clock_wheel_slots
                ADD COLUMN IF NOT EXISTS `type` VARCHAR(20) NOT NULL DEFAULT 'music'");
        }

        // Rename sort_order -> slot_order only if sort_order still exists
        $hasSortOrder = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'station_clock_wheel_slots' AND COLUMN_NAME = 'sort_order'",
            [$db]
        );
        if ($hasSortOrder > 0) {
            $this->addSql("ALTER TABLE station_clock_wheel_slots
                CHANGE COLUMN sort_order slot_order SMALLINT NOT NULL DEFAULT 0");
        } else {
            $this->addSql("ALTER TABLE station_clock_wheel_slots
                ADD COLUMN IF NOT EXISTS slot_order SMALLINT NOT NULL DEFAULT 0");
        }

        $this->addSql("ALTER TABLE station_clock_wheel_slots
            ADD COLUMN IF NOT EXISTS algorithm VARCHAR(30) NOT NULL DEFAULT 'random',
            MODIFY COLUMN duration_seconds SMALLINT NULL DEFAULT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // Restore station_clock_wheels to previous shape
        $this->addSql("ALTER TABLE station_clock_wheels
            DROP COLUMN color,
            ADD COLUMN description LONGTEXT NULL,
            ADD COLUMN strict_timing TINYINT(1) NOT NULL DEFAULT 1,
            MODIFY COLUMN name VARCHAR(200) NOT NULL
        ");

        // Restore station_clock_wheel_slots to previous shape
        $this->addSql("ALTER TABLE station_clock_wheel_slots
            DROP COLUMN algorithm,
            ADD COLUMN label VARCHAR(150) NULL,
            ADD COLUMN position_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            CHANGE COLUMN `type`      slot_type  VARCHAR(30) NOT NULL DEFAULT 'music',
            CHANGE COLUMN slot_order  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            MODIFY COLUMN duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 210
        ");
    }
}
