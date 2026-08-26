<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives top-of-hour news deterministic playout priority immediately after the
 * legal ID while preserving the existing track-sensitive behavior for
 * bottom-of-hour bulletins.
 */
final class TopOfHourNewsConfigWriter implements EventSubscriberInterface
{
    private const int NEWS_FAIL_OPEN_SECONDS = 10;

    /**
     * ConfigWriter still owns the legacy news generator. We suppress that one
     * callback for a single configuration dispatch, then write the coordinated
     * version below.
     *
     * @var array<int, true>
     */
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

    public function suppressLegacyNewsConfig(WriteLiquidsoapConfiguration $event): void
    {
        $config = $event->getBackendConfig();

        if (!($config->ai_news_enabled ?? false)) {
            return;
        }

        $stationId = $event->getStation()->id;
        $this->suppressedByStation[$stationId] = true;

        // ConfigWriter::writeNewsBulletinConfiguration runs at priority 28.
        // Restore this value immediately in our priority-27 callback.
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

        $topEnabled = $config->ai_news_top_of_hour ?? true;
        $bottomEnabled = $config->ai_news_bottom_of_hour ?? false;

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
                $config->top_of_hour_id_enabled,
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
            $event->appendLines([
                'top_of_hour_active_boundary = ref(-1)',
            ]);
        }

        // ConfigWriter has already built radio_before_top_of_hour,
        // top_of_hour_queue, and the TOH transition functions at priority 30.
        // Rebuild only the two outer priority layers:
        //
        // legal ID > top news > schedules/requests/music.
        $event->appendBlock(
            <<<LIQ
            news_bulletin_queue = request.queue(
              id="news_bulletin",
              timeout=settings.azuracast.request_timeout()
            )

            radio_with_priority_news = switch(
              id="news_bulletin_fallback",
              track_sensitive=false,
              [
                ({
                  news_bulletin_queue.is_ready() and
                  top_of_hour_active_boundary() < 0
                }, news_bulletin_queue),
                ({ true }, radio_before_top_of_hour)
              ]
            )

            radio = fallback(
              id="top_of_hour_priority_fallback",
              track_sensitive=false,
              transitions=[to_top_of_hour, from_top_of_hour],
              [top_of_hour_queue, radio_with_priority_news]
            )
            LIQ
        );

        if (!$topOfHourIdEnabled) {
            $event->appendBlock(
                <<<LIQ
                def queue_top_news_bulletin() =
                  now = time()

                  if now - last_news_bulletin_push() >= 120. then
                    if is_within_active_hours() then
                      last_news_bulletin_push := now
                      news_bulletin_queue.push(request.create(news_bulletin_request))
                      log("AI News: Queued top-of-hour bulletin for priority playback.")
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

        $failOpenSeconds = self::NEWS_FAIL_OPEN_SECONDS;

        // The final radio wrapper above must observe clock-wheel-owned IDs too,
        // not only IDs that arrive through the dedicated top_of_hour_queue.
        $event->appendLines([
            'source.methods(radio).on_track(synchronous=true, top_of_hour_mark_legal_id)',
        ]);

        $event->appendBlock(
            <<<LIQ
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

            def queue_armed_top_news(boundary) =
              if pending_top_news_boundary() == boundary and
                 queued_top_news_boundary() != boundary then
                now = time()
                queued_top_news_boundary := boundary
                pending_top_news_boundary := -1
                last_news_bulletin_push := now
                news_bulletin_queue.push(request.create(news_bulletin_request))
                log("AI News: Prequeued behind legal ID for boundary #{boundary}.")
              end
            end

            # Prequeue as soon as the legal ID is known to be on air. The priority
            # switch is gated by top_of_hour_active_boundary, so the ready news
            # request cannot interrupt a clock-wheel ID or the dedicated TOH ID.
            def top_news_bulletin_watch() =
              now = time()
              pending = pending_top_news_boundary()

              if pending > 0 then
                id_started = top_of_hour_last_served_boundary() == pending
                fail_open = int_of_float(now) >= pending + {$failOpenSeconds}

                if id_started then
                  queue_armed_top_news(pending)
                elsif fail_open then
                  queued_top_news_boundary := pending
                  pending_top_news_boundary := -1
                  last_news_bulletin_push := now
                  news_bulletin_queue.push(request.create(news_bulletin_request))
                  log("AI News: Legal ID was not observed; released by fail-open for boundary #{pending}.")
                end
              end

              queued = queued_top_news_boundary()
              if queued > 0 and
                 top_of_hour_active_boundary() == queued and
                 int_of_float(now) >= queued + {$failOpenSeconds} then
                top_of_hour_active_boundary := -1
                log("Top of hour: cleared stale legal-ID active gate for boundary #{queued}.")
              end

              1.0
            end

            # Mark the ID complete using Liquidsoap's measured remaining position.
            # The half-second delayed release lands on the actual track end and
            # lets the news request resolve in advance without cutting the ID.
            def top_of_hour_id_near_end(position, metadata) =
              if metadata["azuracast_top_of_hour_id"] == "true" then
                boundary = top_of_hour_active_boundary()

                if boundary > 0 and queued_top_news_boundary() != boundary then
                  queue_armed_top_news(boundary)
                end

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
            return 'def is_within_active_hours() = true end';
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            return 'def is_within_active_hours() = true end';
        }

        $startMinutes = ((int)$matches[1] * 60) + (int)$matches[2];
        $endMinutes = ((int)$matches[3] * 60) + (int)$matches[4];

        $tzOffsetMinutes = (int)($timezone->getOffset(new DateTimeImmutable('now', $timezone)) / 60);
        $utcStart = ($startMinutes - $tzOffsetMinutes + 1440) % 1440;
        $utcEnd = ($endMinutes - $tzOffsetMinutes + 1440) % 1440;

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

    /**
     * @param array<mixed> $activeDays
     */
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
