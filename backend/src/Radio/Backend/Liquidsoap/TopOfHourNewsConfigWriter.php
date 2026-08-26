<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Coordinates AI News with the station-wide legal-ID playout chain.
 *
 * Top-of-hour bulletin order is deterministic:
 * legal ID -> AI News -> schedules/requests/music.
 *
 * Bottom-of-hour bulletins retain the normal track-sensitive requests behavior.
 */
final class TopOfHourNewsConfigWriter implements EventSubscriberInterface
{
    private const int TOP_NEWS_FAIL_OPEN_SECONDS = 10;

    /** @var array<int, true> */
    private array $suppressedByStation = [];

    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => [
                ['suppressLegacyNewsConfig', 29],
                ['writeCoordinatedNewsConfig', 27],
            ],
        ];
    }

    /**
     * ConfigWriter's legacy block puts top-of-hour news in the generic requests
     * queue. That queue is track-sensitive, which is why music can win after the
     * ID. Suppress only that generated block, then restore the entity immediately.
     */
    public function suppressLegacyNewsConfig(WriteLiquidsoapConfiguration $event): void
    {
        $config = $event->getBackendConfig();

        if (!($config->ai_news_enabled ?? false)) {
            return;
        }

        $stationId = $event->getStation()->id;
        $this->suppressedByStation[$stationId] = true;
        $config->ai_news_enabled = false;
    }

    public function writeCoordinatedNewsConfig(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $config = $event->getBackendConfig();
        $stationId = $station->id;

        $wasSuppressed = $this->suppressedByStation[$stationId] ?? false;
        unset($this->suppressedByStation[$stationId]);

        if (!$wasSuppressed) {
            return;
        }

        // Restore the real station setting before lower-priority config writers run.
        $config->ai_news_enabled = true;

        $bulletinPath = ConfigWriter::toRawString(
            $station->getRadioTempDir() . '/news_bulletin.mp3'
        );
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

        $topEnabled = (bool)($config->ai_news_top_of_hour ?? true);
        $bottomEnabled = (bool)($config->ai_news_bottom_of_hour ?? false);

        // Preserve the existing behavior for legacy configurations with neither
        // checkbox explicitly populated.
        if (!$topEnabled && !$bottomEnabled) {
            $topEnabled = true;
        }

        $cronDays = self::buildCronDays($config->ai_news_active_days ?? []);
        $activeHours = self::buildActiveHoursCheck(
            $config->ai_news_active_hours ?? '',
            $station->getTimezoneObject(),
        );

        $event->appendBlock(
            <<<LIQ
            news_bulletin_path = {$bulletinPath}
            news_bulletin_request = {$bulletinRequest}
            last_news_bulletin_push = ref(0.)
            {$activeHours}
            LIQ
        );

        if ($topEnabled) {
            $this->writeTopOfHourNews(
                $event,
                (bool)$config->top_of_hour_id_enabled,
                $cronDays,
            );
        }

        if ($bottomEnabled) {
            $event->appendBlock(
                <<<LIQ
                def queue_bottom_news_bulletin() =
                  now = time()

                  if now - last_news_bulletin_push() >= 120. then
                    if is_within_active_hours() then
                      last_news_bulletin_push := now
                      requests.push(request.create(news_bulletin_request))
                      log("AI News: Queued bottom-of-hour bulletin for playback.")
                    else
                      log("AI News: Skipped - outside active hours window.")
                    end
                  else
                    log("AI News: skipped duplicate news bulletin within cooldown.")
                  end
                end

                cron.add("29 * * * {$cronDays}", {queue_bottom_news_bulletin()})
                LIQ
            );
        }
    }

    private function writeTopOfHourNews(
        WriteLiquidsoapConfiguration $event,
        bool $topOfHourIdEnabled,
        string $cronDays,
    ): void {
        if (!$topOfHourIdEnabled) {
            // Without legal-ID protection, keep top news on the existing
            // track-sensitive requests path instead of introducing a hard cut.
            $event->appendBlock(
                <<<LIQ
                def queue_top_news_bulletin() =
                  now = time()

                  if now - last_news_bulletin_push() >= 120. then
                    if is_within_active_hours() then
                      last_news_bulletin_push := now
                      requests.push(request.create(news_bulletin_request))
                      log("AI News: Queued top-of-hour bulletin for playback.")
                    else
                      log("AI News: Skipped - outside active hours window.")
                    end
                  else
                    log("AI News: skipped duplicate news bulletin within cooldown.")
                  end
                end

                cron.add("59 * * * {$cronDays}", {queue_top_news_bulletin()})
                LIQ
            );
            return;
        }

        $failOpenSeconds = self::TOP_NEWS_FAIL_OPEN_SECONDS;

        // ConfigWriter has already built radio_before_top_of_hour,
        // top_of_hour_queue and the TOH transition functions. Replace only the
        // final outer selector with one explicit broadcast-priority stack.
        //
        // The news request is not inserted until an ID is actually observed on
        // air. Once inserted, the legal-ID queue remains first priority, so news
        // cannot consume or interrupt the ID; it becomes the very next source.
        $event->appendBlock(
            <<<LIQ
            top_of_hour_news_queue = request.queue(
              id="top_of_hour_news",
              timeout=settings.azuracast.request_timeout()
            )

            radio = fallback(
              id="top_of_hour_priority_fallback",
              track_sensitive=false,
              transitions=[to_top_of_hour, from_top_of_hour, from_top_of_hour],
              [top_of_hour_queue, top_of_hour_news_queue, radio_before_top_of_hour]
            )

            # TopOfHourConfigWriter attached its callbacks before this final wrapper
            # existed. Keep final-output boundary accounting synchronous as well.
            source.methods(radio).on_track(synchronous=true, top_of_hour_mark_legal_id)

            pending_top_news_boundary = ref(-1)
            queued_top_news_boundary = ref(-1)

            def arm_top_news_bulletin() =
              now = time()

              if is_within_active_hours() then
                seconds_in_hour = top_of_hour_seconds_in_station_hour(now)
                boundary = int_of_float(now) + (3600 - seconds_in_hour)

                if pending_top_news_boundary() != boundary and
                   queued_top_news_boundary() != boundary and
                   now - last_news_bulletin_push() >= 120. then
                  pending_top_news_boundary := boundary
                  log("AI News: Armed for top-of-hour boundary #{boundary}.")
                end
              else
                log("AI News: Skipped - outside active hours window.")
              end
            end

            def queue_top_news_for_boundary(boundary, reason) =
              if pending_top_news_boundary() == boundary and
                 queued_top_news_boundary() != boundary then
                queued_top_news_boundary := boundary
                pending_top_news_boundary := -1
                last_news_bulletin_push := time()
                top_of_hour_news_queue.push(request.create(news_bulletin_request))
                log("AI News: Queued after legal ID for boundary #{boundary} (#{reason}).")
              end
            end

            def top_news_bulletin_watch() =
              now = time()
              pending = pending_top_news_boundary()

              if pending > 0 then
                if top_of_hour_last_served_boundary() == pending then
                  queue_top_news_for_boundary(pending, "legal-id-started")
                elsif int_of_float(now) >= pending + {$failOpenSeconds} then
                  # Never lose the bulletin if a legal ID cannot be observed. The
                  # legal-ID source still has first priority if it arrives late.
                  queue_top_news_for_boundary(pending, "fail-open")
                end
              end

              1.0
            end

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
            return 'def is_within_active_hours() = true end';
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            return 'def is_within_active_hours() = true end';
        }

        $startMinutes = ((int)$matches[1] * 60) + (int)$matches[2];
        $endMinutes = ((int)$matches[3] * 60) + (int)$matches[4];

        $offsetMinutes = (int)($timezone->getOffset(new DateTimeImmutable('now', $timezone)) / 60);
        $utcStart = ($startMinutes - $offsetMinutes + 1440) % 1440;
        $utcEnd = ($endMinutes - $offsetMinutes + 1440) % 1440;

        if ($utcStart <= $utcEnd) {
            return <<<LIQ
def is_within_active_hours() =
  local_time = time()
  hour = int_of_float(local_time / 3600.0) mod 24
  minute = int_of_float(local_time / 60.0) mod 60
  current = hour * 60 + minute
  current >= {$utcStart} and current < {$utcEnd}
end
LIQ;
        }

        return <<<LIQ
def is_within_active_hours() =
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
            $activeDays,
        );
        $days = array_values(array_unique(array_filter(
            $days,
            static fn(int $day): bool => $day >= 1 && $day <= 7,
        )));
        sort($days);

        if ($days === []) {
            return '*';
        }

        return implode(',', array_map(
            static fn(int $day): int => $day % 7,
            $days,
        ));
    }
}
