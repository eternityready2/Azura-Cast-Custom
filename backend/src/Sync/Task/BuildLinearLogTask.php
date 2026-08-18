<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Radio\AutoDJ\LinearLogBuilder;
use Monolog\LogRecord;
use Throwable;

/**
 * Hourly top-up for any station with `linear_log_enabled` turned on: keeps its
 * playout log built out to `linear_log_hours` ahead of real time, on top of
 * whatever the live AutoDJ short lookahead is already doing. Off by default
 * per-station -- see StationBackendConfiguration::$linear_log_enabled.
 */
final class BuildLinearLogTask extends AbstractTask
{
    // Minimum station-media count before we'll even attempt a deep build. Below
    // this, a station whose compliance plugin (e.g. DMCA) rejects most of its
    // library will just fail every attempt anyway -- attempting it hourly wastes
    // real CPU on a search that can't succeed. The live AutoDJ tick will keep
    // retrying regardless (that's its job); this only skips the extra hourly
    // deep-build load, not live playback.
    private const int MIN_MEDIA_COUNT = 10;

    public function __construct(
        private readonly LinearLogBuilder $linearLogBuilder,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '7 * * * *';
    }

    public static function isLongTask(): bool
    {
        return true;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            if (!$station->backend_config->linear_log_enabled) {
                continue;
            }

            if (!$station->supportsAutoDjQueue()) {
                continue;
            }

            $this->logger->pushProcessor(
                function (LogRecord $record) use ($station) {
                    $record->extra['station'] = [
                        'id' => $station->id,
                        'name' => $station->name,
                    ];
                    return $record;
                }
            );

            try {
                $mediaCount = (int)$this->em->createQuery(
                    'SELECT COUNT(sm.id) FROM App\Entity\StationMedia sm WHERE sm.storage_location = :storageLocation'
                )
                    ->setParameter('storageLocation', $station->media_storage_location)
                    ->getSingleScalarResult();

                if ($mediaCount < self::MIN_MEDIA_COUNT) {
                    $this->logger->warning(
                        'Skipping linear log build: station has too few media items to build a meaningful deep log.',
                        ['media_count' => $mediaCount]
                    );
                    continue;
                }

                $this->linearLogBuilder->build($station);
            } catch (Throwable $e) {
                $this->logger->error(
                    'Linear log build failed: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            } finally {
                $this->logger->popProcessor();
            }
        }
    }
}
