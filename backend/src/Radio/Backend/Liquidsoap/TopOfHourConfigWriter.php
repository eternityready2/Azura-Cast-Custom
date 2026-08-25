<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationBackendConfiguration;
use App\Entity\StationMedia;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the wall-clock fallback for the dedicated legal-ID queue.
 */
final class TopOfHourConfigWriter implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => [
                ['disableLegacyHardTrigger', 31],
                ['writeTopOfHourFallback', 29],
            ],
        ];
    }

    /**
     * Disable the legacy independent source switch before ConfigWriter builds it.
     * The dedicated top-of-hour queue is the only legal-ID takeover path.
     */
    public function disableLegacyHardTrigger(WriteLiquidsoapConfiguration $event): void
    {
        $config = $event->getBackendConfig();

        if (!$config->top_of_hour_id_enabled || !$config->top_of_hour_hard_trigger_enabled) {
            return;
        }

        $event->appendLines([
            'settings.azuracast.top_of_hour_hard_trigger_enabled := false',
        ]);
    }

    public function writeTopOfHourFallback(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $config = $event->getBackendConfig();

        if (!$config->top_of_hour_id_enabled || !$config->top_of_hour_hard_trigger_enabled) {
            return;
        }

        $safetyMedia = $this->resolveSafetyMedia($station, $config);
        if (!$safetyMedia instanceof StationMedia) {
            return;
        }

        $safetyAnnotations = ConfigWriter::annotateArray([
            'title' => $safetyMedia->title,
            'artist' => $safetyMedia->artist,
            'duration' => $safetyMedia->getCalculatedLength(),
            'song_id' => $safetyMedia->song_id,
            'media_id' => $safetyMedia->id,
            'azuracast_legal_id' => true,
        ]);
        $safetyRequest = ConfigWriter::toRawString(
            'annotate:' . $safetyAnnotations
            . ',liq_disable_autocue="true":media:' . ltrim($safetyMedia->path, '/')
        );

        $safetyDuration = $safetyMedia->getCalculatedLength();
        $requiredLeadSeconds = (int)ceil(
            $safetyDuration + $config->top_of_hour_finish_buffer_seconds
        );
        $triggerLeadSeconds = min(
            $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station),
            max(
                1,
                $requiredLeadSeconds + (int)ceil($config->top_of_hour_hard_trigger_seconds)
            )
        );

        $timezone = $station->getTimezoneObject();
        $timezoneOffsetSeconds = $timezone->getOffset(new DateTimeImmutable('now', $timezone));
        $stationHourOffsetSeconds = (($timezoneOffsetSeconds % 3600) + 3600) % 3600;

        $event->appendBlock(
            <<<LIQ
            top_of_hour_last_served_boundary = ref(-1)
            top_of_hour_last_hard_push_boundary = ref(-1)
            top_of_hour_hard_trigger_lead_seconds = {$triggerLeadSeconds}
            top_of_hour_station_hour_offset_seconds = {$stationHourOffsetSeconds}
            top_of_hour_hard_trigger_request = {$safetyRequest}

            def top_of_hour_seconds_in_station_hour(now) =
              (now + top_of_hour_station_hour_offset_seconds) mod 3600
            end

            def top_of_hour_mark_legal_id(metadata) =
              now = int_of_float(time())
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if metadata["azuracast_legal_id"] == "true" and seconds_in_hour >= 3480 then
                boundary = now - seconds_in_hour + 3600
                top_of_hour_last_served_boundary := boundary
                log("Top of hour: legal ID started for boundary #{boundary}.")
              end
            end

            source.methods(top_of_hour_queue).on_track(synchronous=false, top_of_hour_mark_legal_id)
            source.methods(radio).on_track(synchronous=false, top_of_hour_mark_legal_id)

            def top_of_hour_hard_trigger_watch() =
              now = int_of_float(time())
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)
              seconds_until_top = 3600 - seconds_in_hour
              boundary = now - seconds_in_hour + 3600

              should_push = seconds_in_hour >= 3480 and
                seconds_until_top <= top_of_hour_hard_trigger_lead_seconds and
                top_of_hour_last_served_boundary() != boundary and
                top_of_hour_last_hard_push_boundary() != boundary and
                not top_of_hour_queue.is_ready()

              if should_push then
                top_of_hour_last_hard_push_boundary := boundary
                top_of_hour_queue.push(request.create(top_of_hour_hard_trigger_request))
                log("Top of hour: hard-clock fallback queued for boundary #{boundary}.")
              end

              1.0
            end

            thread.run.recurrent(
              fast=false,
              delay=1.0,
              { top_of_hour_hard_trigger_watch() }
            )
            LIQ
        );
    }

    private function resolveSafetyMedia(
        Station $station,
        StationBackendConfiguration $config,
    ): ?StationMedia {
        /** @var StationMedia[] $media */
        $media = $this->em->createQuery(
            <<<'DQL'
                SELECT m FROM App\Entity\StationMedia m
                WHERE m.storage_location = :storageLocation
                AND m.type IN (:types)
                ORDER BY m.id ASC
            DQL
        )->setParameters([
            'storageLocation' => $station->media_storage_location,
            'types' => StationMediaTypes::stationIdTypeValues(),
        ])->getResult();

        if ($media === []) {
            return null;
        }

        $maxSeconds = max(1, $config->top_of_hour_id_max_seconds);
        $fitting = array_values(array_filter(
            $media,
            static fn(StationMedia $item): bool => $item->getCalculatedLength() <= $maxSeconds,
        ));

        if ($fitting === []) {
            return null;
        }

        return $fitting[0];
    }
}
