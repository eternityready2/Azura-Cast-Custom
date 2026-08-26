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
    private const int PHP_CLAIM_GRACE_SECONDS = 5;
    private const int PRE_BOUNDARY_HOLD_SECONDS = 75;
    private const int POST_BOUNDARY_HOLD_SECONDS = 30;

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

        // The shared boundary state is also used by the coordinated AI News
        // handoff, so write it whenever TOH protection is enabled. The actual
        // wall-clock safety push below still obeys the hard-trigger setting.
        if (!$config->top_of_hour_id_enabled) {
            return;
        }

        $hardTriggerConfigured = $config->top_of_hour_hard_trigger_enabled;
        $safetyMedia = $hardTriggerConfigured
            ? $this->resolveSafetyMedia($station, $config)
            : null;
        $fallbackEnabled = $hardTriggerConfigured && $safetyMedia instanceof StationMedia;

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
        $claimGraceSeconds = self::PHP_CLAIM_GRACE_SECONDS;
        $holdStartSecond = HourBoundaryPlanner::HOUR_SECONDS - self::PRE_BOUNDARY_HOLD_SECONDS;
        $postBoundaryHoldSeconds = self::POST_BOUNDARY_HOLD_SECONDS;

        $event->appendBlock(
            <<<LIQ
            top_of_hour_last_served_boundary = ref(-1)
            top_of_hour_last_hard_push_boundary = ref(-1)
            top_of_hour_claimed_boundary = ref(-1)
            top_of_hour_claimed_at = ref(-1.)
            top_of_hour_claimed_request_id = ref(-1)
            top_of_hour_claim_grace_seconds = {$claimGraceSeconds}.
            top_of_hour_hard_trigger_enabled = {$fallbackEnabledLiq}
            top_of_hour_hard_trigger_lead_seconds = {$triggerLeadSeconds}
            top_of_hour_hard_trigger_request = {$safetyRequest}

            def top_of_hour_seconds_in_station_hour(now) =
              local_now = time.local(now)
              local_now.min * 60 + local_now.sec
            end

            # In the final minute, do not let a freshly-prefetched music track
            # start if the legal ID for this boundary has not aired yet. This is
            # track-sensitive: a song already on air keeps playing naturally,
            # but once it ends the normal source waits for the TOH chain instead
            # of starting a throwaway track that will be cut a few seconds later.
            def top_of_hour_hold_new_track() =
              now = time()
              now_seconds = int_of_float(now)
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if seconds_in_hour >= {$holdStartSecond} then
                boundary = now_seconds + (3600 - seconds_in_hour)
                top_of_hour_last_served_boundary() != boundary
              elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then
                # If a song crossed :00, keep the next normal track held briefly
                # for the just-started hour until its legal ID is observed.
                boundary = now_seconds - seconds_in_hour
                top_of_hour_last_served_boundary() != boundary
              else
                false
              end
            end

            radio_before_top_of_hour_unheld = radio_before_top_of_hour
            top_of_hour_preboundary_hold = blank(
              id="top_of_hour_preboundary_hold",
              duration=1.
            )
            radio_before_top_of_hour = switch(
              id="top_of_hour_preboundary_hold_switch",
              track_sensitive=true,
              [
                ({ top_of_hour_hold_new_track() }, top_of_hour_preboundary_hold),
                ({ true }, radio_before_top_of_hour_unheld)
              ]
            )

            # Rebuild the outer TOH wrapper around the held normal-program source.
            # The legal-ID queue remains non-track-sensitive and can take over
            # immediately whenever it becomes ready.
            radio = fallback(
              id="top_of_hour_hold_fallback",
              track_sensitive=false,
              transitions=[to_top_of_hour, from_top_of_hour],
              [top_of_hour_queue, radio_before_top_of_hour]
            )

            def top_of_hour_clear_claim() =
              top_of_hour_claimed_boundary := -1
              top_of_hour_claimed_at := -1.
              top_of_hour_claimed_request_id := -1
            end

            def top_of_hour_claim(value) =
              boundary = int_of_string(default=-1, value)

              if boundary < 0 then
                "invalid"
              elsif top_of_hour_last_served_boundary() == boundary or
                    top_of_hour_last_hard_push_boundary() == boundary then
                "busy"
              elsif top_of_hour_claimed_boundary() == boundary then
                "owned"
              else
                top_of_hour_claimed_boundary := boundary
                top_of_hour_claimed_at := time()
                top_of_hour_claimed_request_id := -1
                "claimed"
              end
            end

            def top_of_hour_commit(value) =
              request_id = int_of_string(default=-1, value)

              if request_id < 0 or top_of_hour_claimed_boundary() < 0 then
                "invalid"
              else
                top_of_hour_claimed_request_id := request_id
                "committed"
              end
            end

            def top_of_hour_release(value) =
              boundary = int_of_string(default=-1, value)

              if top_of_hour_claimed_boundary() == boundary and
                 top_of_hour_last_served_boundary() != boundary and
                 top_of_hour_last_hard_push_boundary() != boundary then
                top_of_hour_clear_claim()
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
              usage="commit <request_id>",
              description="Commit the Liquidsoap request ID owned by PHP.",
              "commit",
              top_of_hour_commit
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
              now_seconds = int_of_float(now)
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if metadata["azuracast_top_of_hour_id"] == "true" then
                boundary =
                  if seconds_in_hour >= 3480 then
                    now_seconds + (3600 - seconds_in_hour)
                  elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then
                    now_seconds - seconds_in_hour
                  else
                    -1
                  end

                if boundary >= 0 then
                  top_of_hour_claimed_boundary := boundary
                  top_of_hour_claimed_at := now
                  top_of_hour_last_served_boundary := boundary
                  log("Top of hour: legal ID started for boundary #{boundary}.")
                end
              end
            end

            # Synchronous callbacks close the race between track start and the
            # one-second fallback watcher.
            source.methods(top_of_hour_queue).on_track(synchronous=true, top_of_hour_mark_legal_id)
            source.methods(radio).on_track(synchronous=true, top_of_hour_mark_legal_id)

            def top_of_hour_hard_trigger_watch() =
              now = time()
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)
              seconds_until_top = 3600 - seconds_in_hour
              boundary = int_of_float(now) + seconds_until_top
              queue_has_pending = top_of_hour_queue.length() > 0
              queue_ready = top_of_hour_queue.is_ready()
              claim_for_boundary = top_of_hour_claimed_boundary() == boundary
              claim_committed = claim_for_boundary and
                top_of_hour_claimed_request_id() >= 0
              claim_recent = claim_for_boundary and
                top_of_hour_claimed_at() >= 0. and
                now - top_of_hour_claimed_at() <= top_of_hour_claim_grace_seconds
              claim_active = claim_for_boundary and
                (claim_committed or claim_recent or queue_has_pending or queue_ready)

              # Only an uncommitted, empty PHP claim may expire. Once Liquidsoap
              # accepted and committed a request ID, the fallback cannot become a
              # second producer for that boundary.
              if claim_for_boundary and not claim_active then
                top_of_hour_clear_claim()
              end

              should_push = top_of_hour_hard_trigger_enabled and
                seconds_in_hour >= 3480 and
                seconds_until_top <= top_of_hour_hard_trigger_lead_seconds and
                top_of_hour_last_served_boundary() != boundary and
                top_of_hour_last_hard_push_boundary() != boundary and
                not claim_active and
                not queue_has_pending and
                not queue_ready

              if should_push then
                # Claim first. A PHP claimant arriving after this point receives
                # "busy" and cannot enqueue a second ID for the same boundary.
                top_of_hour_claimed_boundary := boundary
                top_of_hour_claimed_at := now
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
