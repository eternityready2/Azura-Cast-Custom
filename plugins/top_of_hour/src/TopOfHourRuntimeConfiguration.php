<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Radio\Enums\LiquidsoapQueues;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Exact on-air enforcement for automatic Top-of-Hour Station IDs.
 *
 * PHP resolves the station-local :59:ss target into an absolute epoch and
 * pre-stages the request. Liquidsoap owns the actual wall-clock switch.
 *
 * The live-proven no-resume mechanism is the one-shot clean-cut callback in
 * the normal AutoDJ cross operator. TOH arms that callback, skips the real
 * request.dynamic leaf, and briefly clocks the existing processed music graph
 * with a dedicated dummy output while the legal ID owns the air. As soon as
 * cross consumes the old buffered tail, the dummy output stops so the fresh
 * successor stays parked at its opening. TOH release never waits on cleanup,
 * so cleanup state cannot deadlock the station on silence.
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
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 15],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $config = $event->getBackendConfig();
        $queueName = LiquidsoapQueues::TopOfHour->value;
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
            # Top-of-Hour Station ID exact wall-clock lane (plugin owned).
            # `top_of_hour_id_enabled` is created earlier by the AI News/TOH
            # coordination subscriber so both systems share one live runtime ref.
            top_of_hour_id = request.queue(
                id="{$queueName}",
                timeout=settings.azuracast.request_timeout()
            )

            top_of_hour_id_fade_seconds = ref({$fadeSeconds})
            top_of_hour_id_target_epoch = ref(0.0)
            top_of_hour_id_boundary_epoch = ref(0.0)
            top_of_hour_id_active = ref(false)
            top_of_hour_id_hard_boundary = ref(false)

            def top_of_hour_id_on_track(_) =
                top_of_hour_id_active := true
                # request.queue removes the active request from its waiting queue;
                # purge any remaining staged tail so one deadline cannot double-ID.
                top_of_hour_id.set_queue([])
                log("Top-of-Hour ID: Station ID is on air; cleared duplicate staged tail.")
            end
            source.methods(top_of_hour_id).on_track(synchronous=false, top_of_hour_id_on_track)

            # HARD hours may have an ID shorter than the remaining time to :00.
            # Keep the TOH lane continuously ready with silence after the ID so
            # ordinary music can never sneak back in before the rigid boundary.
            top_of_hour_hard_hold = blank(id="top_of_hour_hard_hold")
            top_of_hour_lane = fallback(
                id="top_of_hour_lane",
                track_sensitive=false,
                transition_length=0.0,
                [top_of_hour_id, top_of_hour_hard_hold]
            )

            # Fade the complete underlying station graph before the deadline.
            # At the target it has reached zero, so the ID itself starts exactly
            # on time rather than waiting for a fade or normal track boundary.
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

            # #160 proved live that clocking this exact processed graph makes the
            # station `cross` callback permanently reject the interrupted song's
            # buffered tail. #161 put that graph behind another switch and the
            # callback never completed. Keep the cleanup source direct instead.
            #
            # The dummy output is normally stopped. At TOH entry it is started
            # only after the clean-cut marker is armed and the current dynamic
            # request is skipped. It computes muted frames until the normal cross
            # callback clears that marker, then stops on the very next frame.
            # This preserves #160/#161's no-resume behavior without clocking the
            # fresh successor through the rest of the ID.
            let {
                audio=top_of_hour_cleanup_audio_track,
                ...top_of_hour_cleanup_non_audio
            } = source.tracks(radio_before_top_of_hour)
            ignore(top_of_hour_cleanup_non_audio)
            top_of_hour_cleanup_audio = source(
                id="top_of_hour_cleanup_audio",
                {audio=top_of_hour_cleanup_audio_track}
            )
            top_of_hour_cleanup_audio = amplify(
                id="top_of_hour_cleanup_audio_gain",
                override=null,
                0.0,
                top_of_hour_cleanup_audio
            )
            top_of_hour_cleanup_driver = output.dummy(
                id="top_of_hour_cleanup_driver",
                fallible=true,
                register_telnet=false,
                start=false,
                top_of_hour_cleanup_audio
            )

            def top_of_hour_cleanup_after_frame() =
                if not azuracast.autodj_clean_cut_pending() then
                    top_of_hour_cleanup_driver.stop()
                    log("Top-of-Hour ID: clean cross boundary consumed; parked fresh AutoDJ successor.")
                end
            end
            source.methods(top_of_hour_cleanup_driver).on_frame(
                synchronous=true,
                top_of_hour_cleanup_after_frame
            )

            def top_of_hour_id_should_play() =
                now = time()
                target = top_of_hour_id_target_epoch()
                boundary = top_of_hour_id_boundary_epoch()

                if not top_of_hour_id_enabled() then
                    false
                elsif top_of_hour_id_active() then
                    if top_of_hour_id_hard_boundary() then
                        # Once a HARD ID takes authority, this lane owns every
                        # frame until :00 even after the ID file itself ends.
                        boundary > 0.0 and now < boundary
                    else
                        # Open hour: the ID file itself is the only hold condition.
                        # Cleanup is deliberately NOT part of this predicate. The
                        # #161 live incident proved that waiting on it can deadlock
                        # the lane on silence and leave legal-ID metadata stuck.
                        top_of_hour_id.is_ready()
                    end
                else
                    target > 0.0
                    and boundary > target
                    and now >= target
                    and now < boundary
                    and top_of_hour_id.is_ready()
                end
            end

            def top_of_hour_id_enter(_, new) =
                # Mark ownership synchronously; the request.queue on_track callback
                # also sets this when metadata arrives, but must not be the timing
                # primitive for a frame-accurate HARD hold.
                top_of_hour_id_active := true

                if not azuracast.live_enabled() then
                    # Preserve the live-proven clean-cut behavior from #160/#161.
                    # Only the cross callback that actually rejects old.source is
                    # allowed to clear the pending marker. #162 cleared it early
                    # and the 11 PM live test proved the old song resumed.
                    azuracast.discard_autodj_current_cleanly()
                    top_of_hour_cleanup_driver.start()
                    log("Top-of-Hour ID: armed clean cross boundary and started direct cleanup driver.")
                end

                new
            end

            def top_of_hour_id_exit(_, new) =
                was_hard = top_of_hour_id_hard_boundary()

                # HARD :00 may cut a long/mis-timed ID. Discard any current/tail
                # request and empty the waiting queue so it cannot reappear.
                top_of_hour_id.skip()
                top_of_hour_id.set_queue([])

                {$newsAfterId}

                # Normal path: the cross callback cleared pending near ID entry
                # and the cleanup driver already stopped. Never hold the TOH lane
                # here. If cleanup somehow remains pending after the entire ID,
                # keep the station on air and log the failure instead of recreating
                # #161's dead-air/stuck-metadata deadlock.
                if azuracast.autodj_clean_cut_pending() then
                    log("Top-of-Hour ID: ERROR clean cross still pending at ID release; TOH will release without waiting.")
                else
                    top_of_hour_cleanup_driver.stop()
                end

                top_of_hour_id_active := false
                top_of_hour_id_hard_boundary := false
                top_of_hour_id_target_epoch := 0.0
                top_of_hour_id_boundary_epoch := 0.0

                if was_hard then
                    log("Top-of-Hour ID: HARD lane released exactly at the :00 boundary to rigid authority.")
                else
                    log("Top-of-Hour ID: open-hour lane released after clean-cut maintenance.")
                end

                new
            end

            radio = switch(
                id="top_of_hour_station_id",
                track_sensitive=false,
                replay_metadata=true,
                transition_length=0.0,
                transitions=[top_of_hour_id_enter, top_of_hour_id_exit],
                [
                    ({top_of_hour_id_should_play()}, top_of_hour_lane),
                    ({true}, radio_before_top_of_hour)
                ]
            )

            # Runtime controls use absolute epochs to avoid timezone/DST ambiguity.
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
                top_of_hour_cleanup_driver.stop()
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
