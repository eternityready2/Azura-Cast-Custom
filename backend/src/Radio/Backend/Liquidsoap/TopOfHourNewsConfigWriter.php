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
 * Normal listener requests, AI DJ clips and bottom-of-hour news remain on the
 * ordinary track-sensitive requests queue. Top-of-hour news uses its own source
 * in the final priority stack so prefetched music cannot win after the legal ID.
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

        // ConfigWriter::writePlaylistConfiguration already created
        // radio_before_top_of_hour, top_of_hour_queue and the TOH transitions.
        // TopOfHourConfigWriter already created shared once-per-boundary state.
        //
        // Final priority is deterministic but track-boundary safe:
        // 1. legal ID
        // 2. top-of-hour AI News
        // 3. all scheduled/normal program audio
        //
        // The legal ID is staged before the natural break. Once it starts, news
        // is queued behind it so the next source transition is also gapless.
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

            top_of_hour_priority_natural_radio = fallback(
              id="top_of_hour_priority_natural_handoff",
              track_sensitive=true,
              [top_of_hour_queue, top_news_bulletin_queue, radio_before_top_of_hour]
            )

            # Preserve the emergency compliance takeover from the TOH writer.
            # Normal ID/news/program transitions remain track-sensitive and do
            # not fade or cut an in-progress song.
            radio = switch(
              id="top_of_hour_priority_emergency_takeover",
              track_sensitive=false,
              [
                ({ top_of_hour_force_takeover() }, top_of_hour_queue),
                ({ true }, top_of_hour_priority_natural_radio)
              ]
            )

            # The final wrapper can also contain a clock-wheel-owned legal ID, so
            # keep the same synchronous boundary marker on the final radio source.
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

            def queue_top_news_for_boundary(boundary, reason) =
              if pending_top_news_boundary() == boundary and
                 queued_top_news_boundary() != boundary then
                queued_top_news_boundary := boundary
                pending_top_news_boundary := -1
                top_news_bulletin_queue.push(request.create(top_news_bulletin_request))
                log("AI News: queued after legal ID for boundary #{boundary} (#{reason}).")
              end
            end

            def top_news_bulletin_watch() =
              now = time()
              pending = pending_top_news_boundary()

              if pending > 0 then
                if top_of_hour_last_served_boundary() == pending then
                  queue_top_news_for_boundary(pending, "legal-id-started")
                elsif int_of_float(now) >= pending + {$failOpenSeconds} then
                  # Do not strand the bulletin if a legal ID could not be observed.
                  # The legal-ID queue is still first priority if it arrives late.
                  queue_top_news_for_boundary(pending, "fail-open")
                end
              end

              1.0
            end

            # The legal ID may now take a clean natural break during minute 58,
            # so arm the bulletin before that earliest normal handoff.
            cron.add("58 * * * {$cronDays}", {arm_top_news_bulletin()})

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
