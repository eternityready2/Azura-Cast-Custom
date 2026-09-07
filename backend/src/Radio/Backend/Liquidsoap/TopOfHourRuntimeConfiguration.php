<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Enums\LiquidsoapQueues;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Exact on-air enforcement for automatic Top-of-Hour Station IDs.
 *
 * PHP resolves the station-local :59:ss target into an absolute epoch and
 * pre-stages the request. Liquidsoap then owns the final deadline at frame
 * resolution. The underlying source is smoothly faded before the target so the
 * ID itself does not have to wait for a fade or a track boundary.
 */
final class TopOfHourRuntimeConfiguration implements EventSubscriberInterface
{
    public function __construct(
        private readonly TopOfHourClock $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // ConfigWriter has already built requests, schedules, crossfade and
            // live input at this point. Wrap that complete source before the
            // final pre-broadcast/output sections are attached.
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 15],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $config = $event->getBackendConfig();
        $queueName = LiquidsoapQueues::TopOfHour->value;
        $enabled = $this->clock->isEnabled($station) ? 'true' : 'false';
        $fadeSeconds = ConfigWriter::toFloat($this->clock->getIdFadeSeconds($station), 1);

        $newsAfterId = ($config->ai_news_enabled && $config->ai_news_top_of_hour)
            ? <<<'LIQ'
                if not was_hard then
                    queue_news_bulletin()
                    log("Top-of-Hour ID: queued top-hour AI News after the ID.")
                end
                LIQ
            : '# Top-hour AI News is disabled; nothing is queued after the ID.';

        $event->appendBlock(
            <<<LIQ
            # Top-of-Hour Station ID exact wall-clock lane.
            # This request queue is populated in advance by PHP and is not part
            # of the ordinary AutoDJ queue cursor.
            top_of_hour_id = request.queue(
                id="{$queueName}",
                timeout=settings.azuracast.request_timeout()
            )

            top_of_hour_id_enabled = ref({$enabled})
            top_of_hour_id_fade_seconds = ref({$fadeSeconds})
            top_of_hour_id_target_epoch = ref(0.0)
            top_of_hour_id_boundary_epoch = ref(0.0)
            top_of_hour_id_active = ref(false)
            top_of_hour_id_hard_boundary = ref(false)

            def top_of_hour_id_on_track(_) =
                top_of_hour_id_active := true
                log("Top-of-Hour ID: Station ID is on air.")
            end
            source.methods(top_of_hour_id).on_track(synchronous=false, top_of_hour_id_on_track)

            # Fade the complete underlying station source *before* the ID target.
            # At the target it has reached zero, so the ID can start exactly on
            # time without a hard chop and without delaying the ID by fade length.
            def top_of_hour_underlying_gain() =
                now = time()
                target = top_of_hour_id_target_epoch()
                fade = top_of_hour_id_fade_seconds()

                if
                    top_of_hour_id_enabled()
                    and top_of_hour_id.is_ready()
                    and target > 0.0
                    and fade > 0.0
                    and now >= target - fade
                    and now < target
                then
                    remaining = (target - now) / fade
                    if remaining < 0.0 then
                        0.0
                    elsif remaining > 1.0 then
                        1.0
                    else
                        remaining
                    end
                else
                    1.0
                end
            end

            radio_before_top_of_hour = amplify(
                id="top_of_hour_prefade",
                override=null,
                {top_of_hour_underlying_gain()},
                radio
            )

            def top_of_hour_id_should_play() =
                now = time()
                target = top_of_hour_id_target_epoch()
                boundary = top_of_hour_id_boundary_epoch()

                if not top_of_hour_id_enabled() then
                    false
                elsif top_of_hour_id_active() then
                    if top_of_hour_id_hard_boundary() then
                        # A rigid programme owns :00. The ID may never delay it.
                        now < boundary and top_of_hour_id.is_ready()
                    else
                        # Open hour: once started, allow the ID to finish naturally.
                        top_of_hour_id.is_ready()
                    end
                else
                    # Before activation a pre-staged request waits for the exact
                    # absolute deadline calculated in the station timezone by PHP.
                    target > 0.0
                    and boundary > target
                    and now >= target
                    and now < boundary
                    and top_of_hour_id.is_ready()
                end
            end

            def top_of_hour_id_enter(_, new) =
                # The old source has already been faded to zero by the dynamic
                # gain above; switch to the ID immediately at the deadline.
                new
            end

            def top_of_hour_id_exit(_, new) =
                now = time()
                boundary = top_of_hour_id_boundary_epoch()
                was_hard = top_of_hour_id_hard_boundary()

                # Music interrupted for the ID must not resume from the middle.
                # A live DJ is only faded under the ID and resumes afterward.
                # At a HARD :00 boundary do not skip the newly-due programme.
                if not azuracast.live_enabled() then
                    if not was_hard or now < boundary then
                        radio_before_top_of_hour.skip()
                    end
                end

                # A HARD boundary may cut the tail of a long/mis-timed ID at :00.
                # Discard any remaining request so it cannot reappear later.
                top_of_hour_id.skip()

                {$newsAfterId}

                top_of_hour_id_active := false
                top_of_hour_id_hard_boundary := false
                top_of_hour_id_target_epoch := 0.0
                top_of_hour_id_boundary_epoch := 0.0
                new
            end

            radio = switch(
                id="top_of_hour_station_id",
                track_sensitive=false,
                replay_metadata=true,
                transition_length=0.0,
                transitions=[top_of_hour_id_enter, top_of_hour_id_exit],
                [
                    ({top_of_hour_id_should_play()}, top_of_hour_id),
                    ({true}, radio_before_top_of_hour)
                ]
            )

            # Runtime controls. The exact target/boundary are absolute epochs,
            # avoiding server-timezone and DST ambiguity.
            def top_of_hour_set_enabled(value) =
                top_of_hour_id_enabled := string.trim(value) == "true"
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="enabled true|false",
                description="Enable or disable automatic Top-of-Hour ID playout.",
                "enabled",
                top_of_hour_set_enabled
            )

            def top_of_hour_set_fade_seconds(value) =
                top_of_hour_id_fade_seconds := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="fade_seconds 1..10",
                description="Set the pre-ID fade duration.",
                "fade_seconds",
                top_of_hour_set_fade_seconds
            )

            def top_of_hour_set_target_epoch(value) =
                top_of_hour_id_target_epoch := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="target_epoch unix_seconds",
                description="Set the exact absolute ID start deadline.",
                "target_epoch",
                top_of_hour_set_target_epoch
            )

            def top_of_hour_set_boundary_epoch(value) =
                top_of_hour_id_boundary_epoch := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="boundary_epoch unix_seconds",
                description="Set the absolute following hour boundary.",
                "boundary_epoch",
                top_of_hour_set_boundary_epoch
            )

            def top_of_hour_set_hard_boundary(value) =
                top_of_hour_id_hard_boundary := string.trim(value) == "true"
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="hard true|false",
                description="Set whether a rigid programme owns the following :00.",
                "hard",
                top_of_hour_set_hard_boundary
            )

            def top_of_hour_clear_queue(_) =
                top_of_hour_id.skip()
                top_of_hour_id.set_queue([])
                top_of_hour_id_active := false
                top_of_hour_id_hard_boundary := false
                top_of_hour_id_target_epoch := 0.0
                top_of_hour_id_boundary_epoch := 0.0
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="clear",
                description="Clear staged Top-of-Hour ID requests and timing state.",
                "clear",
                top_of_hour_clear_queue
            )
            LIQ
        );
    }
}
