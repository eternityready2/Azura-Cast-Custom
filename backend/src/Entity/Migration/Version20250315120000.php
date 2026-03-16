<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20250315120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add advanced recurrence fields to station_schedules for bi-weekly, monthly, and custom patterns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_interval SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_monthly_pattern VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_monthly_day SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_monthly_week SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_monthly_day_of_week SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_end_type VARCHAR(20) DEFAULT \'never\'');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_end_after INT DEFAULT NULL');
        $this->addSql('ALTER TABLE station_schedules ADD recurrence_end_date VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_type');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_interval');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_monthly_pattern');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_monthly_day');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_monthly_week');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_monthly_day_of_week');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_end_type');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_end_after');
        $this->addSql('ALTER TABLE station_schedules DROP recurrence_end_date');
    }
}
