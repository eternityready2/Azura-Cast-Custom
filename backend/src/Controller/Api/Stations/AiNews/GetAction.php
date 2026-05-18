<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiNews;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\AiNewsGenerator;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
        path: '/station/{station_id}/ai-news',
        operationId: 'getStationAiNewsSettings',
        summary: 'Get current AI news bulletin settings and generation status.',
        tags: [OpenApi::TAG_STATIONS_BROADCASTING],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class GetAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());
        $backendConfig = $station->backend_config;

        return $response->withJson([
            'ai_news_enabled' => $backendConfig->ai_news_enabled,
            'ai_news_intro' => $backendConfig->ai_news_intro,
            'ai_news_reporter_name' => $backendConfig->ai_news_reporter_name,
            'ai_news_source_urls' => $backendConfig->ai_news_source_urls,
            'ai_news_story_count' => $backendConfig->ai_news_story_count,
            'ai_news_active_hours' => $backendConfig->ai_news_active_hours,
            'ai_news_active_days' => $backendConfig->ai_news_active_days,
            'ai_news_top_of_hour' => $backendConfig->ai_news_top_of_hour,
            'ai_news_bottom_of_hour' => $backendConfig->ai_news_bottom_of_hour,
            'ai_news_voice_model_path' => $backendConfig->ai_news_voice_model_path,
            'ai_news_outro' => $backendConfig->ai_news_outro,
            'ai_news_last_generation_status' => $station->ai_news_last_generation_status,
            'ai_news_last_generation_time' => $station->ai_news_last_generation_time?->format('Y-m-d\TH:i:s\Z'),
            'ai_news_last_error' => $station->ai_news_last_error,
            'dashboard' => $this->buildDashboardPayload($station),
            'voice_options' => AiNewsGenerator::AVAILABLE_VOICE_MODELS,
        ]);
    }

    public static function buildDashboardPayload(\App\Entity\Station $station): array
    {
        $backendConfig = $station->backend_config;
        $bulletinPath = $station->getRadioTempDir() . '/' . AiNewsGenerator::OUTPUT_FILENAME;
        $fileExists = file_exists($bulletinPath);
        $latestBulletin = is_array($station->ai_news_latest_bulletin)
            ? $station->ai_news_latest_bulletin
            : [];

        $fileInfo = null;
        if ($fileExists) {
            $fileInfo = [
                'exists' => true,
                'size' => filesize($bulletinPath),
                'modified_at' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($bulletinPath)),
            ];
        }

        return [
            'latest_bulletin' => [
                'generated_at' => $latestBulletin['generated_at'] ?? null,
                'story_count' => $latestBulletin['story_count'] ?? null,
                'source_urls' => $latestBulletin['source_urls'] ?? [],
                'source_results' => $latestBulletin['source_results'] ?? [],
                'elapsed_seconds' => $latestBulletin['elapsed_seconds'] ?? null,
                'output_filename' => $latestBulletin['output_filename'] ?? null,
                'headline_preview' => $latestBulletin['headline_preview'] ?? [],
            ],
            'file_info' => $fileInfo,
            'next_bulletin_time' => self::computeNextBulletinTime(
                $backendConfig->ai_news_active_hours,
                $backendConfig->ai_news_active_days,
                $station,
                $backendConfig->ai_news_top_of_hour,
                $backendConfig->ai_news_bottom_of_hour
            ),
            'current_time_station' => (new \DateTimeImmutable('now', $station->getTimezoneObject()))->format(DATE_ATOM),
            'tts_engine' => 'piper',
            'audio_available' => $fileExists,
            'bulletin_url' => BulletinGetAction::getBulletinUrl($station),
        ];
    }

    private static function computeNextBulletinTime(
        ?string $activeHours,
        array $activeDays,
        \App\Entity\Station $station,
        bool $topOfHour,
        bool $bottomOfHour
    ): ?string {
        if (!$topOfHour && !$bottomOfHour) {
            return null;
        }

        $activeDays = self::normalizeActiveDays($activeDays);
        $now = new \DateTimeImmutable('now', $station->getTimezoneObject());
        $scheduleMinutes = self::getScheduleMinutes($topOfHour, $bottomOfHour);

        if (null === $activeHours || '' === trim($activeHours)) {
            return self::findNextScheduledTime($now, $scheduleMinutes, $activeDays)?->format(DATE_ATOM);
        }

        $activeHours = trim($activeHours);

        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            $startMinutes = ((int) $matches[1]) * 60 + (int) $matches[2];
            $endMinutes = ((int) $matches[3]) * 60 + (int) $matches[4];

            return self::findNextScheduledTimeInWindow($now, $scheduleMinutes, $startMinutes, $endMinutes, $activeDays)?->format(DATE_ATOM);
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $activeHours, $matches)) {
            $startMinutes = ((int) $matches[1]) * 60;
            $endMinutes = ((int) $matches[2]) * 60;

            return self::findNextScheduledTimeInWindow($now, $scheduleMinutes, $startMinutes, $endMinutes, $activeDays)?->format(DATE_ATOM);
        }

        return self::findNextScheduledTime($now, $scheduleMinutes, $activeDays)?->format(DATE_ATOM);
    }

    private static function getScheduleMinutes(bool $topOfHour, bool $bottomOfHour): array
    {
        $scheduleMinutes = [];

        if ($topOfHour) {
            $scheduleMinutes[] = 0;
        }

        if ($bottomOfHour) {
            $scheduleMinutes[] = 30;
        }

        sort($scheduleMinutes);

        return $scheduleMinutes;
    }

    private static function normalizeActiveDays(array $activeDays): array
    {
        $normalizedDays = array_map(
            static fn(mixed $day): int => (int) $day,
            $activeDays
        );
        $normalizedDays = array_values(array_unique(array_filter(
            $normalizedDays,
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($normalizedDays);

        return $normalizedDays;
    }

    private static function isWeekdayAllowed(\DateTimeImmutable $candidate, array $activeDays): bool
    {
        if ([] === $activeDays) {
            return true;
        }

        return in_array((int) $candidate->format('N'), $activeDays, true);
    }

    private static function findNextScheduledTime(
        \DateTimeImmutable $now,
        array $scheduleMinutes,
        array $activeDays
    ): ?\DateTimeImmutable {
        for ($hourOffset = 0; $hourOffset <= 168; $hourOffset++) {
            $candidateHour = $now->modify(sprintf('+%d hour', $hourOffset));

            foreach ($scheduleMinutes as $minute) {
                $candidate = $candidateHour->setTime((int) $candidateHour->format('G'), $minute);
                if (!self::isWeekdayAllowed($candidate, $activeDays)) {
                    continue;
                }

                if ($candidate > $now) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function findNextScheduledTimeInWindow(
        \DateTimeImmutable $now,
        array $scheduleMinutes,
        int $startMinutes,
        int $endMinutes,
        array $activeDays
    ): ?\DateTimeImmutable {
        for ($hourOffset = 0; $hourOffset <= 168; $hourOffset++) {
            $candidateHour = $now->modify(sprintf('+%d hour', $hourOffset));
            $hour = (int) $candidateHour->format('G');

            foreach ($scheduleMinutes as $minute) {
                $candidate = $candidateHour->setTime($hour, $minute);
                $candidateMinutes = $hour * 60 + $minute;

                if (!self::isWeekdayAllowed($candidate, $activeDays)) {
                    continue;
                }

                if (!self::isMinuteWithinWindow($candidateMinutes, $startMinutes, $endMinutes)) {
                    continue;
                }

                if ($candidate > $now) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function isMinuteWithinWindow(int $candidateMinutes, int $startMinutes, int $endMinutes): bool
    {
        if ($startMinutes <= $endMinutes) {
            return $candidateMinutes >= $startMinutes && $candidateMinutes < $endMinutes;
        }

        return $candidateMinutes >= $startMinutes || $candidateMinutes < $endMinutes;
    }
}
