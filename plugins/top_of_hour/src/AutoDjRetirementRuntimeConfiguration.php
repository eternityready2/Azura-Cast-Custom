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
            let azuracast.autodj_retirement_song_hint = ref("")
            let azuracast.autodj_generation = ref(0)
            let azuracast.autodj_retirement_generation = ref(0)
            let azuracast.autodj_current_song_id = ref("")
            let azuracast.autodj_retire_current = ref(fun () -> ())

            # The clock gate supplies the song_id from the fully processed source
            # that is actually feeding air. This identity has priority over the
            # leaf request.dynamic current request, which may already have advanced
            # while crossfade/processing still contains the audible predecessor.
            def azuracast.set_autodj_retirement_song_hint(song_id) =
                azuracast.autodj_retirement_song_hint := string.trim(song_id)
            end

            # Replacement next-song callback. During a retirement transaction it
            # carries the exact excluded song. PHP resets all unplayed rows already
            # sent to AutoDJ because this transaction purges the entire transport
            # prefetch queue and the processed fallback above it.
            def azuracast.autodj_next_song() =
                try
                    j = json()
                    if azuracast.autodj_retired_song_id() != "" then
                        j.add("exclude_song_id", azuracast.autodj_retired_song_id())
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
                    current = dynamic.current()
                    hinted_song_id = azuracast.autodj_retirement_song_hint()

                    # The processed on-air identity wins. request.dynamic.current()
                    # is only a fallback because it can point at the next request
                    # after crossfade has already buffered/advanced the leaf source.
                    if hinted_song_id != "" then
                        azuracast.autodj_retired_song_id := hinted_song_id
                    elsif null.defined(current) then
                        current_request = null.get(current)
                        current_song_id = list.assoc(
                            default=azuracast.autodj_current_song_id(),
                            "song_id",
                            request.metadata(current_request)
                        )
                        if current_song_id != "" then
                            azuracast.autodj_retired_song_id := current_song_id
                        end
                    elsif azuracast.autodj_current_song_id() != "" then
                        azuracast.autodj_retired_song_id := azuracast.autodj_current_song_id()
                    end
                    azuracast.autodj_retirement_song_hint := ""

                    # Destroy the exact active request object independently of
                    # which song identity won above. This releases its RID and
                    # temporary resources; the leaf request cannot be reused.
                    if null.defined(current) then
                        request.destroy(force=true, null.get(current))
                    end

                    # Establish the freshness baseline before clearing/skipping.
                    azuracast.autodj_retirement_generation := azuracast.autodj_generation()

                    queued = dynamic.queue()
                    def destroy_queued(req) =
                        request.destroy(force=true, req)
                    end
                    list.iter(destroy_queued, queued)
                    dynamic.set_queue([])

                    # Skip the dynamic source after every queued request has been
                    # destroyed so it cannot advance into a stale prefetched item.
                    dynamic.skip()
                    log(
                        level=2,
                        label="azuracast.autodj",
                        "Retired audible AutoDJ song, destroyed active/prefetched requests, and activated song quarantine."
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
