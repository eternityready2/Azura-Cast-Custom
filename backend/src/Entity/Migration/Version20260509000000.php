<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260509000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move AI News runtime status fields from station.backend_config JSON to dedicated station columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station ADD ai_news_last_generation_status VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE station ADD ai_news_last_generation_time DATETIME(6) DEFAULT NULL');
        $this->addSql('ALTER TABLE station ADD ai_news_last_error LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE station ADD ai_news_latest_bulletin JSON DEFAULT NULL');
    }

    public function postUp(Schema $schema): void
    {
        // Backfill from existing station.backend_config JSON
        $this->connection->executeQuery(
            <<<'SQL'
            UPDATE station
            SET
                ai_news_last_generation_status = JSON_UNQUOTE(JSON_EXTRACT(backend_config, '$.ai_news_last_generation_status')),
                ai_news_last_generation_time = STR_TO_DATE(
                    JSON_UNQUOTE(JSON_EXTRACT(backend_config, '$.ai_news_last_generation_time')),
                    '%Y-%m-%dT%H:%i:%sZ'
                ),
                ai_news_last_error = JSON_UNQUOTE(JSON_EXTRACT(backend_config, '$.ai_news_last_error')),
                ai_news_latest_bulletin = JSON_EXTRACT(backend_config, '$.ai_news_latest_bulletin')
            WHERE
                backend_config IS NOT NULL
                AND (
                    JSON_EXTRACT(backend_config, '$.ai_news_last_generation_status') IS NOT NULL
                    OR JSON_EXTRACT(backend_config, '$.ai_news_last_generation_time') IS NOT NULL
                    OR JSON_EXTRACT(backend_config, '$.ai_news_last_error') IS NOT NULL
                    OR JSON_EXTRACT(backend_config, '$.ai_news_latest_bulletin') IS NOT NULL
                )
            SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station DROP ai_news_last_generation_status');
        $this->addSql('ALTER TABLE station DROP ai_news_last_generation_time');
        $this->addSql('ALTER TABLE station DROP ai_news_last_error');
        $this->addSql('ALTER TABLE station DROP ai_news_latest_bulletin');
    }
}
