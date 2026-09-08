<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Installs a request.dynamic implementation with explicit retirement semantics.
 *
 * Priority 31 is intentional: the common AzuraCast runtime is already included
 * by ConfigWriter at 35, while the normal playlist/AutoDJ graph is written at 30.
 * These definitions therefore replace the common AutoDJ helpers before the
 * station graph calls azuracast.enable_autodj().
 */
final class AutoDjRetirementRuntimeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 31],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $event->appendBlock(
            <<<'LIQ'
            # AutoDJ retirement transport (Top-of-Hour plugin).
            # No retirement state is active during ordinary playback, so the
            # normal AutoDJ path is unchanged until a clock lane explicitly
            # retires the currently playing request.
            let azuracast.autodj_retired_song_id = ref("")
            let azuracast.autodj_retired_queue_ids = ref("")
            let azuracast.autodj_generation = ref(0)
            let azuracast.autodj_retirement_generation = ref(0)
            let azuracast.autodj_current_song_id = ref("")
            let azuracast.autodj_retire_current = ref(fun () -> ())

            def azuracast.autodj_retirement_append_sq_id(ids, req) =
                sq_id = list.assoc(default="", "sq_id", request.metadata(req))
                if sq_id == "" then
                    ids
                elsif ids == "" then
                    sq_id
                else
                    "#{ids},#{sq_id}"
                end
            end

            # Replacement next-song callback. During a retirement transaction it
            # carries the exact excluded song plus IDs of prefetched requests that
            # were destroyed locally so PHP can reconcile sent_to_autodj state
            # before selecting the post-clock request.
            def azuracast.autodj_next_song() =
                try
                    j = json()
                    if azuracast.autodj_retired_song_id() != "" then
                        j.add("exclude_song_id", azuracast.autodj_retired_song_id())
                    end
                    if azuracast.autodj_retired_queue_ids() != "" then
                        j.add("reset_sq_ids", azuracast.autodj_retired_queue_ids())
                    end

                    api_response = azuracast.api_call(
                        "nextsong",
                        json.stringify(compact=true, j)
                    )

                    if null.defined(api_response) then
                        let json.parse (
                            {
                                uri,
                            } : {
                                uri: string,
                            }
                        ) = null.get(api_response)

                        azuracast.autodj_generation := azuracast.autodj_generation() + 1
                        azuracast.autodj_retired_queue_ids := ""
                        request.create(uri)
                    else
                        null
                    end
                catch err do
                    log(
                        level=1,
                        label="azuracast.autodj",
                        "ERROR parsing JSON: #{err}"
                    )

                    null
                end
            end

            # The generic source reference cannot expose request.dynamic queue()
            # and current(). This callback is replaced inside enable_autodj where
            # the specialized request.dynamic value is still in scope.
            def azuracast.discard_autodj_current() =
                if azuracast.autodj_transport_ready() then
                    retire = azuracast.autodj_retire_current()
                    retire()
                end
            end

            def azuracast.prefetch_autodj_next() =
                if azuracast.autodj_transport_ready() then
                    fetch_next = azuracast.autodj_fetch_next()
                    fetch_next()
                    log(level=2, label="azuracast.autodj", "Prefetch requested for fresh AutoDJ transport request.")
                end
            end

            # A ready source is only considered fresh during quarantine after at
            # least one /nextsong response has been accepted after the retirement
            # generation. This prevents a pre-existing prefetched request from
            # satisfying the rejoin gate.
            def azuracast.autodj_fresh_ready() =
                transport_ready = azuracast.autodj_transport_ready()
                source_ready = transport_ready and source.is_ready(azuracast.autodj_transport())

                if azuracast.autodj_retired_song_id() == "" then
                    source_ready
                else
                    source_ready
                    and azuracast.autodj_generation() > azuracast.autodj_retirement_generation()
                end
            end

            # Replacement enable_autodj keeps AzuraCast's normal startup/fallback
            # behavior while publishing closures that retain request.dynamic's
            # specialized current()/queue()/set_queue()/fetch() methods.
            def azuracast.enable_autodj(s) =
                dynamic = request.dynamic(
                    id="next_song",
                    timeout=settings.azuracast.request_timeout(),
                    retry_delay=10.,
                    azuracast.autodj_next_song
                )

                azuracast.autodj_transport := dynamic
                azuracast.autodj_transport_ready := true
                azuracast.autodj_fetch_next := fun () -> dynamic.fetch()

                def retire_dynamic_request() =
                    ids = ref("")
                    current = dynamic.current()

                    if null.defined(current) then
                        current_request = null.get(current)
                        ids := azuracast.autodj_retirement_append_sq_id(ids(), current_request)
                        current_song_id = list.assoc(
                            default=azuracast.autodj_current_song_id(),
                            "song_id",
                            request.metadata(current_request)
                        )
                        if current_song_id != "" then
                            azuracast.autodj_retired_song_id := current_song_id
                        end

                        # Destroy the exact active request object as well as
                        # skipping the source below. This releases the request RID
                        # and any temporary resources; it cannot be reused later.
                        request.destroy(force=true, current_request)
                    elsif azuracast.autodj_current_song_id() != "" then
                        azuracast.autodj_retired_song_id := azuracast.autodj_current_song_id()
                    end

                    # Establish the freshness baseline before clearing/skipping.
                    azuracast.autodj_retirement_generation := azuracast.autodj_generation()

                    queued = dynamic.queue()
                    def destroy_queued(req) =
                        ids := azuracast.autodj_retirement_append_sq_id(ids(), req)
                        request.destroy(force=true, req)
                    end
                    list.iter(destroy_queued, queued)
                    dynamic.set_queue([])

                    azuracast.autodj_retired_queue_ids := ids()
                    dynamic.skip()
                    log(
                        level=2,
                        label="azuracast.autodj",
                        "Retired current AutoDJ request, destroyed prefetched queue, and activated song quarantine."
                    )
                end
                azuracast.autodj_retire_current := retire_dynamic_request

                def autodj_track_started(m) =
                    song_id = list.assoc(default="", "song_id", m)
                    if song_id != "" then
                        azuracast.autodj_current_song_id := song_id

                        retired = azuracast.autodj_retired_song_id()
                        if retired != "" and song_id != retired then
                            azuracast.autodj_retired_song_id := ""
                            azuracast.autodj_retired_queue_ids := ""
                            log(
                                level=2,
                                label="azuracast.autodj",
                                "Different AutoDJ song is on air; local retirement quarantine cleared."
                            )
                        end
                    end
                end
                source.methods(dynamic).on_track(synchronous=false, autodj_track_started)

                dynamic_startup = fallback(
                    id="dynamic_startup",
                    track_sensitive=false,
                    [
                        dynamic,
                        source.available(
                            blank(id="autodj_startup_blank", duration=120.),
                            predicate.activates({azuracast.autodj_is_loading()})
                        )
                    ]
                )

                s = fallback(id="autodj_fallback", track_sensitive=true, [dynamic_startup, s])

                ref_dynamic = ref(dynamic)
                thread.run.recurrent(delay=0.25, { azuracast.wait_for_next_song(ref_dynamic()) })

                s
            end
            LIQ
        );
    }
}
