<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\Station;
use App\Entity\StationMount;
use App\Entity\Enums\PlaylistSources;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class StationHealthMountSummary
{
    #[OA\Property(example: 'Default Mount')]
    public string $name = '';

    #[OA\Property(example: 'mp3')]
    public string $format = '';

    #[OA\Property(example: 128)]
    public int $bitrate = 0;

    #[OA\Property(example: true)]
    public bool $is_default = false;
}

#[OA\Schema(type: 'object')]
final class StationHealthReport
{
    #[OA\Property(example: true)]
    public bool $is_online = false;

    #[OA\Property(example: true)]
    public bool $autodj_enabled = false;

    #[OA\Property(example: 42)]
    public int $listeners_now = 0;

    #[OA\Property(example: 1250)]
    public int $media_tracks = 0;

    #[OA\Property(example: 3)]
    public int $do_not_play_count = 0;

    #[OA\Property(example: 1)]
    public int $empty_playlists = 0;

    #[OA\Property(example: 5)]
    public int $clock_wheel_fallbacks_7d = 0;

    #[OA\Property(example: 2)]
    public int $clock_wheel_deferred_7d = 0;

    #[OA\Property(example: 95.5, nullable: true)]
    public ?float $legal_id_compliance_percent = null;

    #[OA\Property(example: 2)]
    public int $upcoming_holidays = 0;

    /**
     * @var StationHealthMountSummary[]
     */
    #[OA\Property(type: 'array', items: new OA\Items(type: 'object'))]
    public array $stream_mounts = [];

    #[OA\Property(example: 'warning', nullable: true)]
    public ?string $overall_status = null;
}

/**
 * Aggregates operational health signals for the station health dashboard (Phase E).
 */
final class StationHealthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockWheelEventRepository $eventRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    public function getReport(Station $station): StationHealthReport
    {
        $tz = $station->getTimezoneObject();
        $since = CarbonImmutable::now($tz)->subDays(7)->startOfDay()->toDateTimeImmutable();

        $report = new StationHealthReport();
        $report->is_online = $station->is_enabled;
        $report->autodj_enabled = $station->supportsAutoDjQueue();
        $report->listeners_now = 0;
        foreach ($station->mounts as $mount) {
            if ($mount instanceof StationMount) {
                $report->listeners_now += $mount->listeners_total;
            }
        }

        $report->media_tracks = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(m.id) FROM App\Entity\StationMedia m
                WHERE m.storage_location = :storageLocation
            DQL
        )->setParameter('storageLocation', $station->media_storage_location)
            ->getSingleScalarResult();

        $report->do_not_play_count = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(m.id) FROM App\Entity\StationMedia m
                WHERE m.storage_location = :storageLocation AND m.do_not_play = 1
            DQL
        )->setParameter('storageLocation', $station->media_storage_location)
            ->getSingleScalarResult();

        $report->empty_playlists = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(p.id) FROM App\Entity\StationPlaylist p
                WHERE p.station = :station AND p.is_enabled = 1
                AND p.source = :source
                AND NOT EXISTS (
                    SELECT 1 FROM App\Entity\StationPlaylistMedia spm WHERE spm.playlist = p
                )
            DQL
        )->setParameter('station', $station)
            ->setParameter('source', PlaylistSources::Songs)
            ->getSingleScalarResult();

        $stationSummary = $this->eventRepo->getStationAnalyticsSummary($station, $since);
        $report->clock_wheel_fallbacks_7d = $stationSummary['fallbacks'];
        $report->clock_wheel_deferred_7d = $stationSummary['deferred'];

        $compliance = $this->eventRepo->getStationTopOfHourLegalIdComplianceSummary(
            $station,
            $since,
            $this->hourBoundaryPlanner->getComplianceToleranceSeconds($station),
        );
        $report->legal_id_compliance_percent = $compliance['compliance_percent'];

        $today = CarbonImmutable::now($tz)->startOfDay();
        $report->upcoming_holidays = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(h.id) FROM App\Entity\StationHolidayOverride h
                WHERE h.station = :station AND h.is_active = 1
                AND h.override_date >= :today
            DQL
        )->setParameter('station', $station)
            ->setParameter('today', $today->toDateTimeImmutable())
            ->getSingleScalarResult();

        foreach ($station->mounts as $mount) {
            if (!$mount instanceof StationMount) {
                continue;
            }

            $summary = new StationHealthMountSummary();
            $summary->name = $mount->name;
            $summary->format = ($mount->autodj_format ?? \App\Radio\Enums\StreamFormats::Mp3)->value;
            $summary->bitrate = $mount->autodj_bitrate ?? 128;
            $summary->is_default = $mount->is_default;
            $report->stream_mounts[] = $summary;
        }

        $report->overall_status = $this->deriveOverallStatus($report);

        return $report;
    }

    private function deriveOverallStatus(StationHealthReport $report): string
    {
        if (!$report->is_online || !$report->autodj_enabled) {
            return 'critical';
        }

        if ($report->clock_wheel_fallbacks_7d > 20) {
            return 'warning';
        }

        if ($report->legal_id_compliance_percent !== null && $report->legal_id_compliance_percent < 90.0) {
            return 'warning';
        }

        if ($report->empty_playlists > 0) {
            return 'caution';
        }

        return 'ok';
    }
}
