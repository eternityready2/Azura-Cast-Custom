<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Features;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Api\Status;
use App\Entity\Listener;
use App\Entity\SongHistory;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Media\MediaProcessor;
use App\Radio\Adapters;
use App\Radio\AutoDJ\LinearLogBuilder;
use App\Service\GuzzleFactory;
use Carbon\CarbonImmutable;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class FeatureSuiteController
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly Adapters $adapters,
        private readonly GuzzleFactory $guzzleFactory,
        private readonly MediaProcessor $mediaProcessor,
        private readonly LinearLogBuilder $linearLogBuilder,
    ) {
    }

    public function getAirCheckAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $config = $request->getStation()->backend_config;

        return $response->withJson([
            'enabled' => $config->aircheck_enabled,
            'interval_minutes' => $config->aircheck_interval_minutes,
            'last_check' => $config->aircheck_last_check,
            'interventions' => array_values($config->aircheck_interventions),
        ]);
    }

    public function saveAirCheckAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $data = (array)$request->getParsedBody();
        $config = $station->backend_config;
        $config->aircheck_enabled = (bool)($data['enabled'] ?? false);
        $config->aircheck_interval_minutes = max(1, min(60, (int)($data['interval_minutes'] ?? 10)));
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }

    public function runAirCheckAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $result = $this->runAirCheck($station, true);
        return $response->withJson($result);
    }

    /** @return array<string, mixed> */
    public function runAirCheck(Station $station, bool $manual = false): array
    {
        $needsRestartBefore = $station->needs_restart;
        $config = $station->backend_config;
        $now = time();
        if (!$manual && (!$config->aircheck_enabled
            || ($now - $config->aircheck_last_check) < ($config->aircheck_interval_minutes * 60))) {
            return ['checked' => false, 'restarted' => []];
        }

        $restarted = [];
        $failures = [];
        foreach (['backend', 'frontend'] as $service) {
            try {
                $adapter = 'backend' === $service
                    ? $this->adapters->getBackendAdapter($station)
                    : $this->adapters->getFrontendAdapter($station);

                if (null === $adapter) {
                    continue;
                }

                if (!$adapter->isRunning($station)) {
                    $adapter->restart($station);
                    $restarted[] = $service;
                }
            } catch (Throwable $e) {
                $failures[] = $service . ': ' . $e->getMessage();
            }
        }

        if ([] !== $restarted || [] !== $failures) {
            $history = $config->aircheck_interventions;
            array_unshift($history, [
                'timestamp' => $now,
                'services' => $restarted,
                'failures' => $failures,
                'manual' => $manual,
            ]);
            $config->aircheck_interventions = array_slice($history, 0, 50);
        }

        $config->aircheck_last_check = $now;
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return [
            'checked' => true,
            'healthy' => [] === $restarted && [] === $failures,
            'restarted' => $restarted,
            'failures' => $failures,
            'timestamp' => $now,
        ];
    }

    public function listShowsAction(ServerRequest $request, Response $response): ResponseInterface
    {
        return $response->withJson(array_values($request->getStation()->backend_config->feature_shows));
    }

    public function saveShowAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $data = (array)$request->getParsedBody();
        $config = $station->backend_config;
        $shows = array_values($config->feature_shows);
        $id = trim((string)($data['id'] ?? ''));
        if ('' === $id) {
            $id = bin2hex(random_bytes(8));
        }

        $show = [
            'id' => $id,
            'name' => trim((string)($data['name'] ?? 'Untitled Show')),
            'description' => trim((string)($data['description'] ?? '')),
            'enabled' => (bool)($data['enabled'] ?? true),
            'color' => (string)($data['color'] ?? '#667eea'),
            'priority' => (string)($data['priority'] ?? 'programme'),
            'allow_overrun' => (bool)($data['allow_overrun'] ?? false),
            'segments' => array_values((array)($data['segments'] ?? [])),
            'schedules' => array_values((array)($data['schedules'] ?? [])),
            'updated_at' => time(),
        ];

        $found = false;
        foreach ($shows as $i => $existing) {
            if (($existing['id'] ?? null) === $id) {
                $shows[$i] = $show;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $shows[] = $show;
        }


        $config->feature_shows = $shows;
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson($show);
    }

    public function deleteShowAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $id = (string)$params['id'];
        $config = $station->backend_config;
        $config->feature_shows = array_values(array_filter(
            $config->feature_shows,
            static fn(array $show): bool => (string)($show['id'] ?? '') !== $id
        ));
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::deleted());
    }

    public function buildLinearLogAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $data = (array)$request->getParsedBody();
        $hours = max(1, min(48, (int)($data['hours'] ?? 24)));

        if (!$station->supportsAutoDjQueue()) {
            throw new InvalidArgumentException('This station does not support the AutoDJ queue.');
        }

        $this->linearLogBuilder->build($station, $hours);

        return $response->withJson([
            'success' => true,
            'hours' => $hours,
        ]);
    }

    public function simulateAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $params = $request->getParams();
        $tz = $station->getTimezoneObject();
        $date = (string)($params['date'] ?? CarbonImmutable::now($tz)->format('Y-m-d'));
        $time = (string)($params['time'] ?? CarbonImmutable::now($tz)->format('H:i'));
        $duration = max(5, min(1440, (int)($params['duration'] ?? 60)));
        $start = CarbonImmutable::parse($date . ' ' . $time, $tz);
        $end = $start->addMinutes($duration);

        $scheduleRows = $this->em->createQuery(
            'SELECT s, p FROM App\\Entity\\StationSchedule s JOIN s.playlist p WHERE p.station = :station AND p.is_enabled = true'
        )->setParameter('station', $station)->getResult();

        $windows = [];
        foreach ($scheduleRows as $schedule) {
            if (!$schedule instanceof StationSchedule || !$schedule->playlist instanceof StationPlaylist) {
                continue;
            }
            $window = $this->scheduleWindow($schedule, $start);
            if (null === $window) {
                continue;
            }
            [$windowStart, $windowEnd] = $window;
            if ($windowEnd <= $start || $windowStart >= $end) {
                continue;
            }
            $windows[] = [
                'start' => $windowStart->format('H:i:s'),
                'end' => $windowEnd->format('H:i:s'),
                'name' => $schedule->playlist->name,
                'type' => $schedule->strict_start || $schedule->is_emergency ? 'priority' : 'playlist',
                'priority' => $schedule->is_emergency ? 100 : ($schedule->strict_start ? 80 : 50),
            ];
        }

        foreach ($station->backend_config->feature_shows as $show) {
            if (!($show['enabled'] ?? true)) {
                continue;
            }
            foreach ((array)($show['schedules'] ?? []) as $showSchedule) {
                $scheduleStartDate = trim((string)($showSchedule['start_date'] ?? ''));
                $scheduleEndDate = trim((string)($showSchedule['end_date'] ?? ''));
                if ('' !== $scheduleStartDate && $date < $scheduleStartDate) {
                    continue;
                }
                if ('' !== $scheduleEndDate && $date > $scheduleEndDate) {
                    continue;
                }

                $days = array_map('intval', (array)($showSchedule['days'] ?? []));
                if ([] !== $days && !in_array((int)$start->isoWeekday(), $days, true)) {
                    continue;
                }

                if (($showSchedule['loop_once'] ?? false)
                    && '' !== $scheduleStartDate
                    && $date !== $scheduleStartDate) {
                    continue;
                }

                $st = (string)($showSchedule['start_time'] ?? '00:00');
                $et = (string)($showSchedule['end_time'] ?? $st);
                $ws = CarbonImmutable::parse($date . ' ' . $st, $tz);
                $we = CarbonImmutable::parse($date . ' ' . $et, $tz);
                if ($we <= $ws) {
                    $we = $we->addDay();
                }
                if ($we > $start && $ws < $end) {
                    $priority = (string)($show['priority'] ?? 'programme');
                    $windows[] = [
                        'start' => $ws->format('H:i:s'),
                        'end' => $we->format('H:i:s'),
                        'name' => (string)($show['name'] ?? 'Show'),
                        'type' => 'show',
                        'priority' => 'priority' === $priority ? 120 : 90,
                    ];
                }
            }
        }

        usort(
            $windows,
            static fn(array $a, array $b): int => [$a['start'], -$a['priority']]
                <=> [$b['start'], -$b['priority']]
        );

        $resolved = [];
        if ([] === $windows) {
            $resolved[] = [
                'start' => $start->format('H:i:s'),
                'end' => $end->format('H:i:s'),
                'name' => 'General Rotation',
                'type' => 'rotation',
            ];
        } else {
            foreach ($windows as $window) {
                $resolved[] = $window;
            }
        }

        return $response->withJson([
            'timezone' => $tz->getName(),
            'schedule_windows' => $windows,
            'resolved_timeline' => $resolved,
            'rotation_gaps' => [] === $windows ? $resolved : [],
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function scheduleWindow(StationSchedule $schedule, CarbonImmutable $day): ?array
    {
        $date = $day->format('Y-m-d');
        if ($schedule->start_date && $date < $schedule->start_date) {
            return null;
        }
        if ($schedule->end_date && $date > $schedule->end_date) {
            return null;
        }

        $days = $schedule->days;
        if ([] !== $days && !in_array((int)$day->isoWeekday(), $days, true)) {
            return null;
        }

        $startText = StationSchedule::displayTimeCode($schedule->start_time);
        $endText = StationSchedule::displayTimeCode($schedule->end_time);
        $start = CarbonImmutable::parse($date . ' ' . $startText, $day->getTimezone());
        $end = CarbonImmutable::parse($date . ' ' . $endText, $day->getTimezone());
        if ($end <= $start) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    public function downloadFromUrlAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();
        $data = (array)$request->getParsedBody();
        $url = trim((string)($data['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
        }

        $parts = parse_url($url);
        $filename = trim((string)($data['filename'] ?? ''));
        if ('' === $filename) {
            $filename = basename((string)($parts['path'] ?? 'download.mp3')) ?: 'download.mp3';
        }
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'download.mp3';

        $directory = trim((string)($data['directory'] ?? ''), '/');
        $destination = '' !== $directory ? $directory . '/' . $filename : $filename;

        $tmp = tempnam($station->getRadioTempDir(), 'url_');
        if (false === $tmp) {
            throw new InvalidArgumentException('Unable to create temporary download file.');
        }

        try {
            $client = $this->guzzleFactory->buildClient();
            $client->request('GET', $url, [
                RequestOptions::SINK => $tmp,
                RequestOptions::TIMEOUT => 120,
                RequestOptions::CONNECT_TIMEOUT => 15,
                RequestOptions::ALLOW_REDIRECTS => ['max' => 5],
                RequestOptions::HTTP_ERRORS => true,
                'headers' => ['User-Agent' => 'AzuraCast URL Importer'],
            ]);

            $size = filesize($tmp) ?: 0;
            if ($size <= 0 || $size > 1024 * 1024 * 1024) {
                throw new InvalidArgumentException('Downloaded file is empty or exceeds the 1 GB limit.');
            }

            $media = $this->mediaProcessor->processAndUpload(
                $station->media_storage_location,
                $destination,
                $tmp
            );
            $tmp = null;

            return $response->withJson([
                'success' => true,
                'path' => $destination,
                'media_id' => $media?->id,
            ]);
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function ppcaReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $rows = $this->historyRows($station, $start, $end);
        $csv = [['Date', 'Time', 'Artist', 'Title', 'Record Label', 'ISRC', 'Unique Listeners']];

        foreach ($rows as $row) {
            $csv[] = [
                $row->timestamp_start->setTimezone($station->getTimezoneObject())->format('Y-m-d'),
                $row->timestamp_start->setTimezone($station->getTimezoneObject())->format('H:i:s'),
                $row->artist ?? '', $row->title ?? '',
                ($row->media?->extra_metadata->toArray()['publisher'] ?? ''),
                $row->media?->isrc ?? '',
                (string)($row->unique_listeners ?? 0),
            ];
        }
        return $response->renderStringAsFile($this->csv($csv), 'text/csv', 'ppca-report.csv');
    }

    public function pplReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $rows = $this->historyRows($station, $start, $end);
        $seconds = 0.0;
        foreach ($rows as $row) {
            $seconds += (float)($row->duration ?? 0);
        }

        $periodHours = max(1.0, ($end->getTimestamp() - $start->getTimestamp()) / 3600);
        $tracksPerHour = count($rows) / $periodHours;

        $listenerRows = $this->em->createQuery(
            'SELECT l FROM App\\Entity\\Listener l WHERE l.station = :station AND l.timestamp_start <= :end AND (l.timestamp_end IS NULL OR l.timestamp_end >= :start)'
        )->setParameter('station', $station)->setParameter('start', $start)->setParameter('end', $end)->getResult();
        $listenerSeconds = 0;
        foreach ($listenerRows as $listener) {
            if (!$listener instanceof Listener) {
                continue;
            }
            $ls = max($start->getTimestamp(), $listener->timestamp_start->getTimestamp());
            $le = min($end->getTimestamp(), ($listener->timestamp_end ?? $end)->getTimestamp());
            $listenerSeconds += max(0, $le - $ls);
        }
        $csv = [
            ['Metric', 'Value'],
            ['Average music tracks webcast per hour', number_format($tracksPerHour, 2, '.', '')],
            ['Total listener hours', number_format($listenerSeconds / 3600, 2, '.', '')],
            ['Total performances', number_format($tracksPerHour * ($listenerSeconds / 3600), 2, '.', '')],
            ['Tracked audio seconds', number_format($seconds, 0, '.', '')],
        ];
        return $response->renderStringAsFile($this->csv($csv), 'text/csv', 'ppl-webcasting-report.csv');
    }

    public function cadenceReportAction(ServerRequest $request, Response $response): ResponseInterface
    {
        [$start, $end] = $this->getReportDates($request);
        $station = $request->getStation();
        $listeners = $this->em->createQuery(
            'SELECT l FROM App\\Entity\\Listener l WHERE l.station = :station AND l.timestamp_start <= :end AND (l.timestamp_end IS NULL OR l.timestamp_end >= :start) ORDER BY l.timestamp_start ASC'
        )->setParameter('station', $station)->setParameter('start', $start)->setParameter('end', $end)->getResult();
        $csv = [['IP address', 'Date', 'Time', 'Stream', 'Duration', 'Status', 'Referrer']];
        foreach ($listeners as $listener) {
            if (!$listener instanceof Listener) {
                continue;
            }
            $s = $listener->timestamp_start->setTimezone($station->getTimezoneObject());
            $e = ($listener->timestamp_end ?? $end)->setTimezone($station->getTimezoneObject());
            $csv[] = [
                $listener->listener_ip,
                $s->format('Y-m-d'),
                $s->format('H:i:s'),
                $listener->mount?->name
                    ?? $listener->remote?->display_name
                    ?? $listener->hls_stream?->name
                    ?? 'default',
                (string)max(0, $e->getTimestamp() - $s->getTimestamp()),
                '200',
                $listener->listener_user_agent,
            ];
        }
        $format = strtolower((string)($request->getParam('format') ?? 'csv'));
        $delimiter = 'txt' === $format ? "\t" : ',';
        $contentType = 'txt' === $format ? 'text/plain' : 'text/csv';
        $extension = 'txt' === $format ? 'txt' : 'csv';

        return $response->renderStringAsFile(
            $this->csv($csv, $delimiter),
            $contentType,
            'cadence-report.' . $extension
        );
    }

    /** @return array{0: CarbonImmutable,1: CarbonImmutable} */
    private function getReportDates(ServerRequest $request): array
    {
        $tz = $request->getStation()->getTimezoneObject();
        $startDate = (string)($request->getParam('start_date') ?? 'first day of this month');
        $endDate = (string)($request->getParam('end_date') ?? 'today');

        $start = CarbonImmutable::parse($startDate, $tz)
            ->startOfDay()
            ->utc();
        $end = CarbonImmutable::parse($endDate, $tz)
            ->endOfDay()
            ->utc();
        return [$start, $end];
    }

    /** @return SongHistory[] */
    private function historyRows(Station $station, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->em->createQuery(
            'SELECT h, m FROM App\\Entity\\SongHistory h LEFT JOIN h.media m WHERE h.station = :station AND h.timestamp_start BETWEEN :start AND :end AND h.is_visible = true ORDER BY h.timestamp_start ASC'
        )->setParameter('station', $station)->setParameter('start', $start)->setParameter('end', $end)->getResult();
    }

    /** @param array<int,array<int,string>> $rows */
    private function csv(array $rows, string $delimiter = ','): string
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, $delimiter, '"', '\\');
        }
        rewind($stream);
        $out = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $out;
    }
}
