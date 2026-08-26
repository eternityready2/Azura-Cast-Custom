<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationBackendConfiguration;
use App\Entity\StationMedia;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Coordinates one owner per top-of-hour boundary and provides the wall-clock
 * fallback when PHP has not already claimed the boundary.
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
        $fallbackEnabled = $safetyMedia instanceof StationMedia;

        if ($fallbackEnabled) {
            $safetyAnnotations = ConfigWriter::annotateArray([
                'title' => $safetyMedia->title,
                'artist' => $safetyMedia->artist,
                'duration' => $safetyMedia->getCalculatedLength(),
                'song_id' => $safetyMedia->song_id,
                'media_id' => $safetyMedia->id,
                'azuracast_legal_id' => true,
                'azuracast_top_of_hour_id' => true,
                'azuracast_top_of_hour_fallback' => true,
            ]);
            $safetyRequest = ConfigWriter::toRawString(
                'annotate:' . $safetyAnnotations
                . ',liq_disable_autocue="true":media:' . ltrim($safetyMedia->path, '/')
            );
            $requiredLeadSeconds = (int)ceil(
                $safetyMedia->getCalculatedLength() + $config->top_of_hour_finish_buffer_seconds
            );
        } else {
            $safetyRequest = ConfigWriter::toRawString('');
            $requiredLeadSeconds = 1;
        }

        $triggerLeadSeconds = min(
            $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station),
            max(
                1,
                $requiredLeadSeconds + (int)ceil($config->top_of_hour_hard_trigger_seconds)
            )
        );
        $fallbackEnabledLiq = $fallbackEnabled ? 'true' : 'false';

        $event->appendBlock(
            <<<LIQ
            top_of_hour_last_served_boundary = ref(-1)
            top_of_hour_last_hard_push_boundary = ref(-1)
            top_of_hour_claimed_boundary = ref(-1)
            top_of_hour_hard_trigger_enabled = {$fallbackEnabledLiq}
            top_of_hour_hard_trigger_lead_seconds = {$triggerLeadSeconds}
            top_of_hour_hard_trigger_request = {$safetyRequest}

            def top_of_hour_seconds_in_station_hour(now) =
              local_now = time.local(now)
              local_now.min * 60 + local_now.sec
            end

            def top_of_hour_claim(value) =
              boundary = int_of_string(value, default=-1)

              if boundary < 0 then
                "invalid"
              elsif top_of_hour_last_served_boundary() == boundary or
                    top_of_hour_last_hard_push_boundary() == boundary then
                "busy"
              elsif top_of_hour_claimed_boundary() == boundary then
                "owned"
              else
                top_of_hour_claimed_boundary := boundary
                "claimed"
              end
            end

            def top_of_hour_release(value) =
              boundary = int_of_string(value, default=-1)

              if top_of_hour_claimed_boundary() == boundary and
                 top_of_hour_last_served_boundary() != boundary and
                 top_of_hour_last_hard_push_boundary() != boundary then
                top_of_hour_claimed_boundary := -1
              end

              "released"
            end

            server.register(
              namespace="top_of_hour",
              usage="claim <boundary>",
              description="Claim one top-of-hour boundary for PHP delivery.",
              "claim",
              top_of_hour_claim
            )

            server.register(
              namespace="top_of_hour",
              usage="release <boundary>",
              description="Release a failed PHP top-of-hour claim.",
              "release",
              top_of_hour_release
            )

            def top_of_hour_mark_legal_id(metadata) =
              now = time()
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if metadata["azuracast_top_of_hour_id"] == "true" and seconds_in_hour >= 3480 then
                boundary = int_of_float(now) + (3600 - seconds_in_hour)
                top_of_hour_claimed_boundary := boundary
                top_of_hour_last_served_boundary := boundary
                log("Top of hour: legal ID started for boundary #{boundary}.")
              end
            end

            # This callback is synchronous so the served marker is updated before
            # the one-second fallback watcher can make another ownership decision.
            source.methods(top_of_hour_queue).on_track(synchronous=true, top_of_hour_mark_legal_id)
            source.methods(radio).on_track(synchronous=true, top_of_hour_mark_legal_id)

            def top_of_hour_hard_trigger_watch() =
              now = time()
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)
              seconds_until_top = 3600 - seconds_in_hour
              boundary = int_of_float(now) + seconds_until_top
              queue_has_pending = list.length(top_of_hour_queue.queue()) > 0

              should_push = top_of_hour_hard_trigger_enabled and
                seconds_in_hour >= 3480 and
                seconds_until_top <= top_of_hour_hard_trigger_lead_seconds and
                top_of_hour_last_served_boundary() != boundary and
                top_of_hour_last_hard_push_boundary() != boundary and
                top_of_hour_claimed_boundary() != boundary and
                not queue_has_pending and
                not top_of_hour_queue.is_ready()

              if should_push then
                # Claim first, then push. A PHP claimant arriving after this point
                # receives "busy" and cannot enqueue a second ID for this boundary.
                top_of_hour_claimed_boundary := boundary
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
