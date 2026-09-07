<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Isolates ordinary automated playout while a broadcast-clock owner has air.
 *
 * This wrapper is written after playlists/news and before crossfade/Harbor. It
 * therefore gates the automated station graph without disconnecting a live DJ;
 * Harbor remains above this lane. TOH and rigid runtime wrappers, written later,
 * independently acquire/release their own hold so one owner cannot accidentally
 * clear the other's hold during a :59 -> :00 handoff.
 */
final class BroadcastClockRuntimeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 26],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $event->appendBlock(
            <<<'LIQ'
            # Broadcast-clock AutoDJ airlock.
            # Separate ownership refs make TOH -> rigid handoff race-safe: TOH
            # releasing its hold can never reopen AutoDJ while rigid still owns air.
            broadcast_clock_toh_hold = ref(false)
            broadcast_clock_rigid_hold = ref(false)

            def broadcast_clock_hold_toh() =
                broadcast_clock_toh_hold := true
                if not azuracast.live_enabled() then
                    azuracast.discard_autodj_current()
                end
            end

            def broadcast_clock_release_toh() =
                broadcast_clock_toh_hold := false
            end

            def broadcast_clock_hold_rigid() =
                broadcast_clock_rigid_hold := true
                if not azuracast.live_enabled() then
                    azuracast.discard_autodj_current()
                end
            end

            def broadcast_clock_release_rigid() =
                broadcast_clock_rigid_hold := false
            end

            def broadcast_clock_automated_playout_allowed() =
                not broadcast_clock_toh_hold() and not broadcast_clock_rigid_hold()
            end

            radio_before_broadcast_clock_gate = radio
            broadcast_clock_hold_silence = blank(id="broadcast_clock_hold_silence")

            radio = switch(
                id="broadcast_clock_autodj_gate",
                track_sensitive=false,
                replay_metadata=true,
                transition_length=0.0,
                [
                    ({broadcast_clock_automated_playout_allowed()}, radio_before_broadcast_clock_gate),
                    ({true}, broadcast_clock_hold_silence)
                ]
            )
            LIQ
        );
    }
}
