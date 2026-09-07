<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Enums\LiquidsoapQueues;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the on-air enforcement layer for automatic Top-of-Hour Station IDs.
 *
 * Queue planning still tries to make the clock clean, but this outer source is
 * the final authority: when a staged ID is ready, it takes air at the configured
 * :59:ss even if the current source is mid-track. The outgoing source is faded
 * under the ID, then discarded so ordinary music cannot resume afterward.
 *
 * The block is always emitted so the TOH page can enable/disable and retime the
 * feature at runtime through server commands without requiring a station restart.
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
        $queueName = LiquidsoapQueues::TopOfHour->value;
        $enabled = $this->clock->isEnabled($station) ? 'true' : 'false';
        $startSecond = $this->clock->getIdStartSecond($station);
        $fadeSeconds = ConfigWriter::toFloat($this->clock->getIdFadeSeconds($station), 1);
        $maxTransition = ConfigWriter::toFloat(TopOfHourClock::MAX_ID_FADE_SECONDS, 1);

        $event->appendBlock(
            <<<LIQ
            # Top-of-Hour Station ID wall-clock runtime guard.
            # The dedicated queue is pre-staged by PHP. It is intentionally kept
            # outside the normal AutoDJ timeline so it can take air at :59:ss.
            top_of_hour_id = request.queue(
                id="{$queueName}",
                timeout=settings.azuracast.request_timeout()
            )
            top_of_hour_id_enabled = ref({$enabled})
            top_of_hour_id_start_second = ref({$startSecond})
            top_of_hour_id_fade_seconds = ref({$fadeSeconds})
            top_of_hour_id_active = ref(false)
            top_of_hour_id_hard_boundary = ref(false)

            def top_of_hour_id_on_track(_) =
                top_of_hour_id_active := true
                log("Top-of-Hour ID: station ID is now on air.")
            end
            source.methods(top_of_hour_id).on_track(synchronous=false, top_of_hour_id_on_track)

            def top_of_hour_id_should_play() =
                local = time.local()
                start_window = local.min == 59 and local.sec >= top_of_hour_id_start_second()

                if not top_of_hour_id_enabled() then
                    false
                elsif top_of_hour_id_active() then
                    if top_of_hour_id_hard_boundary() then
                        # HARD TOH: a rigid programme owns :00, so the ID source
                        # loses authority at the exact hour boundary even if the
                        # operator selected a start time too late for this ID.
                        local.min == 59 and top_of_hour_id.is_ready()
                    else
                        # SOFT ETM: once the ID has started, let it finish naturally
                        # even if its tail crosses :00. Pending news waits behind it.
                        top_of_hour_id.is_ready()
                    end
                else
                    # Before activation, readiness alone is never enough. A staged
                    # request must wait for the exact operator-selected :59:ss.
                    start_window and top_of_hour_id.is_ready()
                end
            end

            # Preserve the complete underlying AzuraCast source (including live,
            # requests, AI News and strict schedule switches) behind the ID guard.
            radio_before_top_of_hour = radio
            ignore(radio_before_top_of_hour)

            def top_of_hour_id_enter(old, new) =
                # ID starts immediately at the wall-clock deadline while the old
                # source fades smoothly underneath it. The transition is additive
                # so fade time never makes the ID itself late.
                add(normalize=false, [
                    new,
                    fade.out(
                        duration=top_of_hour_id_fade_seconds(),
                        track_sensitive=false,
                        old
                    )
                ])
            end

            def top_of_hour_id_exit(_, new) =
                local = time.local()

                # AutoDJ music that was deliberately interrupted must not resume
                # from the middle after the ID. Live DJs are different: they are
                # merely faded under the ID and resume when it finishes.
                #
                # At a HARD :00 boundary do not issue a skip after the boundary;
                # the underlying strict schedule switch must be allowed to start
                # the programme immediately and remain untouched.
                if not azuracast.live_enabled() then
                    if not top_of_hour_id_hard_boundary() or local.min == 59 then
                        radio_before_top_of_hour.skip()
                    end
                end

                # If HARD TOH cut a long/mis-timed ID at :00, discard its remaining
                # request so it cannot reappear later. On natural completion this
                # is a harmless no-op.
                top_of_hour_id.skip()
                top_of_hour_id_active := false
                top_of_hour_id_hard_boundary := false
                new
            end

            radio = switch(
                id="top_of_hour_station_id",
                track_sensitive=false,
                replay_metadata=true,
                transition_length={$maxTransition},
                transitions=[top_of_hour_id_enter, top_of_hour_id_exit],
                [
                    ({ top_of_hour_id_should_play() }, top_of_hour_id),
                    ({ true }, radio_before_top_of_hour)
                ]
            )

            # Runtime controls used by the Top-of-Hour API and staging task.
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

            def top_of_hour_set_start_second(value) =
                top_of_hour_id_start_second := int_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="start_second 0..59",
                description="Set the second inside minute :59 when the ID must begin.",
                "start_second",
                top_of_hour_set_start_second
            )

            def top_of_hour_set_fade_seconds(value) =
                top_of_hour_id_fade_seconds := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="fade_seconds 1..10",
                description="Set the outgoing-source fade duration before the ID owns air.",
                "fade_seconds",
                top_of_hour_set_fade_seconds
            )

            def top_of_hour_set_hard_boundary(value) =
                top_of_hour_id_hard_boundary := string.trim(value) == "true"
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="hard true|false",
                description="Tell the runtime whether a rigid programme owns the next :00.",
                "hard",
                top_of_hour_set_hard_boundary
            )

            def top_of_hour_clear_queue(_) =
                top_of_hour_id.set_queue([])
                top_of_hour_id_active := false
                top_of_hour_id_hard_boundary := false
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="clear",
                description="Clear staged Top-of-Hour ID requests.",
                "clear",
                top_of_hour_clear_queue
            )
            LIQ
        );
    }
}
