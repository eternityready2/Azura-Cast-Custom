<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Chains top-of-hour AI News directly behind the legal ID.
 *
 * The ordinary requests queue remains track-sensitive for listener requests,
 * AI DJ clips and bottom-of-hour news. Top-of-hour news uses its own priority
 * queue so a prefetched music track cannot win the boundary after the legal ID.
 */
final class TopOfHourNewsConfigWriter implements EventSubscriberInterface
{
    private const int NEWS_FAIL_OPEN_SECONDS = 10;

    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => [
                ['writeTopOfHourNews', 27],
            ],
        ];
    }

    public function writeTopOfHourNews(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $config = $event->getBackendConfig();

        if (
            !($config->ai_news_enabled ?? false)
            || !($config->ai_news_top_of_hour ?? true)
            || !$config->top_of_hour_id_enabled
        ) {
            return;
        }

        $annotations = ConfigWriter::annotateArray([
            'azuracast_ai_news' => true,
            'title' => 'News Hour',
            'artist' => 'Eternity Ready',
            'album' => 'News Bulletin',
            'genre' => 'News',
            'comment' => 'Hourly news bulletin',
            'art' => 'https://eternityready.com/public/logo1USE-THIS.png',
        ]);
        $bulletinRequest = ConfigWriter::toRawString(
            'annotate:' . $annotations . ':' . $station->getRadioTempDir() . '/news_bulletin.mp3'
        );

        $cronDays = self::buildCronDays($config->ai_news_active_days ?? []);
        $activeHoursCheck = self::buildActiveHoursCheck(
            $config->ai_news_active_hours ?? '',
            $station->getTimezoneObject(),
        );
        $failOpenSeconds = self::NEWS_FAIL_OPEN_SECONDS;
        $staleGateSeconds = max(
            self::NEWS_FAIL_OPEN_SECONDS,
            $config->top_of_hour_id_max_seconds + 10,
        );

        // ConfigWriter::writePlaylistConfiguration has already created
        // radio_before_top_of_hour, top_of_hour_queue and the TOH transitions.
        // TopOfHourConfigWriter has already created the shared boundary state.
        $event->appendBlock(
            <<<LIQ
            top_news_bulletin_request = {$bulletinRequest}
            {$activeHoursCheck}

            top_news_bulletin_queue = request.queue(
              id="top_news_bulletin",
              timeout=settings.azuracast.request_timeout()
            )

            pending_top_news_boundary = ref(-1)
            queued_top_news_boundary = ref(-1)

            # Explicit playout priority:
            # legal ID > top-of-hour news > schedules/requests/music.
            radio_with_priority_top_news = switch(
              id="top_news_bulletin_fallback",
              track_sensitive=false,
              [
                ({
                  top_news_bulletin_queue.is_ready() and
                  top_of_hour_active_boundary() < 0
                }, top_news_bulletin_queue),
                ({ true }, radio_before_top_of_hour)
              ]
            )

            radio = fallback(
              id="top_of_hour_priority_fallback",
              track_sensitive=false,
              transitions=[to_top_of_hour, from_top_of_hour],
              [top_of_hour_queue, radio_with_priority_top_news]
            )

            # Observe the final wrapper too, so a clock-wheel-owned legal ID
            # participates in the same boundary chain as the station TOH queue.
            source.methods(radio).on_track(
              synchronous=true,
              top_of_hour_mark_legal_id
            )

            def arm_top_news_bulletin() =
              now = time()

              if is_within_top_news_active_hours() then
                seconds_in_hour = top_of_hour_seconds_in_station_hour(now)
                boundary = int_of_float(now) + (3600 - seconds_in_hour)

                if pending_top_news_boundary() != boundary and
                   queued_top_news_boundary() != boundary then
                  pending_top_news_boundary := boundary
                  log("AI News: armed for top-of-hour boundary #{boundary}.")
                end
              else
                log("AI News: skipped - outside active hours window.")
              end
            end

            def queue_armed_top_news(boundary) =
              if pending_top_news_boundary() == boundary and
                 queued_top_news_boundary() != boundary then
                queued_top_news_boundary := boundary
                pending_top_news_boundary := -1
                top_news_bulletin_queue.push(request.create(top_news_bulletin_request))
                log("AI News: prequeued behind legal ID for boundary #{boundary}.")
              end
            end

            def top_news_bulletin_watch() =
              now = time()
              pending = pending_top_news_boundary()

              if pending > 0 then
                id_started = top_of_hour_last_served_boundary() == pending
                fail_open = int_of_float(now) >= pending + {$failOpenSeconds}

                if id_started then
                  # Resolve the local bulletin while the ID is still on air.
                  # The active-boundary gate below keeps it from interrupting.
                  queue_armed_top_news(pending)
                elsif fail_open then
                  # If no legal ID can be observed, do not strand the bulletin.
                  queued_top_news_boundary := pending
                  pending_top_news_boundary := -1
                  top_news_bulletin_queue.push(request.create(top_news_bulletin_request))
                  log("AI News: legal ID not observed; fail-open released boundary #{pending}.")
                end
              end

              # A failed end callback must never strand the news queue forever.
              # Use the configured maximum ID length as the safety horizon so
              # this fallback cannot cut a legitimately late legal ID short.
              queued = queued_top_news_boundary()
              if queued > 0 and
                 top_of_hour_active_boundary() == queued and
                 int_of_float(now) >= queued + {$staleGateSeconds} then
                top_of_hour_active_boundary := -1
                log("Top of hour: cleared stale legal-ID active gate for boundary #{queued}.")
              end

              1.0
            end

            # Release the news gate at the actual end of the legal ID. Remaining
            # position is reliable for the local MP3 and keeps the handoff tied to
            # audio completion instead of an assumed 37/38-second duration.
            def top_of_hour_id_near_end(position, metadata) =
              if metadata["azuracast_top_of_hour_id"] == "true" then
                boundary = top_of_hour_active_boundary()

                if boundary > 0 then
                  queue_armed_top_news(boundary)

                  thread.run(
                    delay=0.5,
                    {
                      if top_of_hour_active_boundary() == boundary then
                        top_of_hour_active_boundary := -1
                        log("Top of hour: legal ID finished for boundary #{boundary}.")
                      end
                    }
                  )
                end
              end
            end

            source.methods(top_of_hour_queue).on_position(
              synchronous=true,
              remaining=true,
              allow_partial=true,
              position=0.5,
              top_of_hour_id_near_end
            )
            source.methods(radio).on_position(
              synchronous=true,
              remaining=true,
              allow_partial=true,
              position=0.5,
              top_of_hour_id_near_end
            )

            cron.add("59 * * * {$cronDays}", {arm_top_news_bulletin()})

            thread.run.recurrent(
              fast=false,
              delay=1.0,
              { top_news_bulletin_watch() }
            )
            LIQ
        );
    }

    private static function buildActiveHoursCheck(
        ?string $activeHours,
        DateTimeZone $timezone,
    ): string {
        if (empty($activeHours)) {
            return 'def is_within_top_news_active_hours() = true end';
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            return 'def is_within_top_news_active_hours() = true end';
        }

        $startMinutes = ((int)$matches[1] * 60) + (int)$matches[2];
        $endMinutes = ((int)$matches[3] * 60) + (int)$matches[4];

        $tzOffsetMinutes = (int)($timezone->getOffset(new DateTimeImmutable('now', $timezone)) / 60);
        $utcStart = ($startMinutes - $tzOffsetMinutes + 1440) % 1440;
        $utcEnd = ($endMinutes - $tzOffsetMinutes + 1440) % 1440;

        if ($utcStart <= $utcEnd) {
            return <<<LIQ
def is_within_top_news_active_hours() =
  local_time = time()
  hour = int_of_float(local_time / 3600.0) mod 24
  minute = int_of_float(local_time / 60.0) mod 60
  current = hour * 60 + minute
  current >= {$utcStart} and current < {$utcEnd}
end
LIQ;
        }

        return <<<LIQ
def is_within_top_news_active_hours() =
  local_time = time()
  hour = int_of_float(local_time / 3600.0) mod 24
  minute = int_of_float(local_time / 60.0) mod 60
  current = hour * 60 + minute
  current >= {$utcStart} or current < {$utcEnd}
end
LIQ;
    }

    /** @param array<mixed> $activeDays */
    private static function buildCronDays(array $activeDays): string
    {
        $days = array_map(
            static fn(mixed $day): int => (int)$day,
            $activeDays
        );
        $days = array_values(array_unique(array_filter(
            $days,
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($days);

        if ([] === $days) {
            return '*';
        }

        return implode(',', array_map(
            static fn(int $day): int => $day % 7,
            $days
        ));
    }
}
