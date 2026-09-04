<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDj;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PodcastSources;
use App\Entity\Podcast;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationPlaylist;
use App\Entity\StationRemote;
use App\Radio\Adapters;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class StationDiagnosticsDashboard
{
    private const int WINDOW_HOURS = 24;
    private const int RAW_LOG_TAIL_BYTES = 512 * 1024;
    private const int MAX_RECENT_ISSUES = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private AirCheckHealthMonitor $airCheckHealthMonitor,
        private StationHealthService $stationHealthService,
        private StationDiagnostics $diagnostics,
        private Adapters $adapters,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSnapshot(Station $station): array
    {
        $generatedAt = time();
        $events = $this->diagnostics->getRecentEvents($station, self::WINDOW_HOURS);
        $runtimeHealth = $this->airCheckHealthMonitor->getSnapshot($station);
        $stationHealth = $this->stationHealthService->getReport($station);

        $issues = [];
        $features = [];

        $features[] = $this->buildStationServicesFeature($station, $runtimeHealth, $issues, $generatedAt);
        $features[] = $this->buildPlaylistsFeature($station, $issues, $generatedAt);
        $features[] = $this->buildClockWheelsFeature($station, $issues, $generatedAt);
        $features[] = $this->buildSmartBlocksFeature($station);
        $features[] = $this->buildLinearLogFeature($station);
        $features[] = $this->buildRemoteStreamsFeature($station, $issues, $generatedAt);
        $features[] = $this->buildRssPodcastsFeature($station, $issues, $generatedAt);
        $features[] = $this->buildAiFeature($station, $issues, $generatedAt);
        $features[] = $this->buildAirCheckFeature($station, $runtimeHealth);

        $eventIssues = $this->buildEventIssues($station, $events);
        $rawLogIssues = $this->scanRuntimeLogSignals($station);
        $issues = [...$issues, ...$eventIssues, ...$rawLogIssues];

        $features = $this->applyIssueStatusToFeatures($features, $issues);
        $issues = $this->sortAndLimitIssues($issues);
        $services = $this->normalizeServices($station, $runtimeHealth);
        $distribution = $this->buildDistribution($features);
        $healthScore = $this->calculateHealthScore($features);
        $overallStatus = $this->calculateOverallStatus($features, $services);

        return [
            'generated_at' => $generatedAt,
            'window_hours' => self::WINDOW_HOURS,
            'overall_status' => $overallStatus,
            'health_score' => $healthScore,
            'counts' => [
                'critical' => $distribution['critical'],
                'warning' => $distribution['warning'],
                'healthy' => $distribution['healthy'],
                'inactive' => $distribution['inactive'],
                'recent_events' => count($events),
                'active_issues' => count(array_filter(
                    $issues,
                    static fn(array $issue): bool => in_array($issue['severity'], ['critical', 'warning'], true)
                )),
                'services_running' => (int)($runtimeHealth['running'] ?? 0),
                'services_total' => (int)($runtimeHealth['total'] ?? 0),
            ],
            'station' => [
                'enabled' => $station->is_enabled,
                'started' => $station->has_started,
                'needs_restart' => $station->needs_restart,
                'autodj_enabled' => $station->supportsAutoDjQueue(),
                'media_tracks' => $stationHealth->media_tracks,
                'listeners_now' => $stationHealth->listeners_now,
                'clock_wheel_fallbacks_7d' => $stationHealth->clock_wheel_fallbacks_7d,
                'clock_wheel_deferred_7d' => $stationHealth->clock_wheel_deferred_7d,
                'legal_id_compliance_percent' => $stationHealth->legal_id_compliance_percent,
            ],
            'distribution' => $distribution,
            'timeline' => $this->buildTimeline($events),
            'features' => $features,
            'services' => $services,
            'recent_issues' => $issues,
        ];
    }

    /**
     * @param array<string, mixed> $runtimeHealth
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function buildStationServicesFeature(
        Station $station,
        array $runtimeHealth,
        array &$issues,
        int $timestamp,
    ): array {
        if (!$station->is_enabled) {
            return $this->feature(
                'station-services',
                __('Station Services'),
                'inactive',
                __('Station is disabled'),
                __('Broadcast services are intentionally inactive.'),
                '0 online',
                'live'
            );
        }

        $configured = array_values(array_filter(
            (array)($runtimeHealth['station_services'] ?? []),
            static fn(mixed $service): bool => is_array($service) && true === ($service['configured'] ?? false)
        ));
        $running = count(array_filter(
            $configured,
            static fn(array $service): bool => true === ($service['running'] ?? null)
        ));

        if ($station->has_started) {
            foreach ($configured as $service) {
                if (false === ($service['running'] ?? null)) {
                    $issues[] = $this->issue(
                        'critical',
                        'station-services',
                        __('Station Services'),
                        sprintf(__('%s is not running'), (string)($service['name'] ?? __('Station service'))),
                        (string)($service['error'] ?? $service['description'] ?? ''),
                        $timestamp,
                        'live'
                    );
                }
            }
        }

        if ($station->needs_restart) {
            $issues[] = $this->issue(
                'warning',
                'station-services',
                __('Station Services'),
                __('Station configuration is waiting for a restart'),
                __('Restart the station for pending broadcast configuration changes to take effect.'),
                $timestamp,
                'state'
            );
        }

        $status = !$station->has_started
            ? 'inactive'
            : ($running === count($configured) ? 'healthy' : 'critical');

        return $this->feature(
            'station-services',
            __('Station Services'),
            $status,
            $station->has_started ? __('Broadcast engine runtime') : __('Station is stopped'),
            $station->has_started
                ? __('Backend and broadcast frontend are checked live when this dashboard loads.')
                : __('The station is enabled but its local services are not started.'),
            sprintf('%d/%d online', $running, count($configured)),
            'live'
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function buildPlaylistsFeature(Station $station, array &$issues, int $timestamp): array
    {
        $enabled = 0;
        $ready = 0;

        foreach ($station->playlists as $playlist) {
            if (!$playlist instanceof StationPlaylist || !$playlist->is_enabled) {
                continue;
            }

            ++$enabled;
            $problem = null;
            $severity = 'warning';

            if (PlaylistSources::Songs === $playlist->source && 0 === $playlist->media_items->count()) {
                $problem = __('Enabled playlist has no media assigned.');
            } elseif (PlaylistSources::Playlists === $playlist->source && 0 === $playlist->playlists->count()) {
                $problem = __('Enabled playlist group has no member playlists.');
            } elseif (PlaylistSources::RemoteUrl === $playlist->source && empty(trim((string)$playlist->remote_url))) {
                $problem = __('Enabled remote playlist has no URL configured.');
                $severity = 'critical';
            }

            if (null !== $problem) {
                $issues[] = $this->issue(
                    $severity,
                    'playlists',
                    __('Playlists'),
                    $playlist->name,
                    $problem,
                    $timestamp,
                    'state'
                );
                continue;
            }

            ++$ready;
        }

        if ($station->supportsAutoDjQueue() && 0 === $ready) {
            $issues[] = $this->issue(
                'critical',
                'playlists',
                __('Playlists'),
                __('AutoDJ has no content-ready playlists'),
                __('No enabled playlist currently has usable content for AutoDJ queue generation.'),
                $timestamp,
                'state'
            );
        }

        $status = 0 === $enabled ? 'inactive' : ($ready === $enabled ? 'healthy' : 'warning');

        return $this->feature(
            'playlists',
            __('Playlists'),
            $status,
            0 === $enabled ? __('No enabled playlists') : __('AutoDJ source readiness'),
            __('Checks enabled song playlists, playlist groups and remote URL playlist configuration.'),
            sprintf('%d/%d ready', $ready, $enabled),
            'state+logs'
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function buildClockWheelsFeature(Station $station, array &$issues, int $timestamp): array
    {
        $active = 0;
        $ready = 0;

        foreach ($station->clock_wheels as $wheel) {
            if (!$wheel instanceof StationClockWheel || !$wheel->is_active) {
                continue;
            }

            ++$active;
            if (0 === $wheel->slots->count()) {
                $issues[] = $this->issue(
                    'warning',
                    'clock-wheels',
                    __('Clock Wheels'),
                    $wheel->name,
                    __('Active Clock Wheel has no playout slots.'),
                    $timestamp,
                    'state'
                );
                continue;
            }

            ++$ready;
        }

        return $this->feature(
            'clock-wheels',
            __('Clock Wheels'),
            0 === $active ? 'inactive' : ($active === $ready ? 'healthy' : 'warning'),
            0 === $active ? __('No active Clock Wheels') : __('Clock scheduling and fallbacks'),
            __('Combines current wheel configuration with deferral, fallback and safeguard events.'),
            sprintf('%d/%d ready', $ready, $active),
            'state+events'
        );
    }

    /** @return array<string, mixed> */
    private function buildSmartBlocksFeature(Station $station): array
    {
        $enabled = 0;
        foreach ($station->playlists as $playlist) {
            if ($playlist instanceof StationPlaylist && $playlist->is_enabled && $playlist->is_smart_block) {
                ++$enabled;
            }
        }

        return $this->feature(
            'smart-blocks',
            __('Smart Blocks'),
            $enabled > 0 ? 'healthy' : 'inactive',
            $enabled > 0 ? __('Dynamic playlist synchronization') : __('No enabled Smart Blocks'),
            __('Synchronization failures are promoted here from the station diagnostics stream.'),
            sprintf('%d active', $enabled),
            'events'
        );
    }

    /** @return array<string, mixed> */
    private function buildLinearLogFeature(Station $station): array
    {
        $enabled = $station->backend_config->linear_log_enabled;

        return $this->feature(
            'linear-log',
            __('Linear Log'),
            $enabled ? 'healthy' : 'inactive',
            $enabled ? __('Rolling playout plan enabled') : __('Linear Log disabled'),
            $enabled
                ? __('Background build failures in the last 24 hours are surfaced as operational issues.')
                : __('This station is not configured to build a rolling Linear Log.'),
            $enabled ? sprintf('%dh window', $station->backend_config->linear_log_hours) : 'Off',
            'events'
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function buildRemoteStreamsFeature(Station $station, array &$issues, int $timestamp): array
    {
        $stationRemotes = 0;
        $remotePlaylists = 0;
        $configured = 0;

        foreach ($station->remotes as $remote) {
            if (!$remote instanceof StationRemote) {
                continue;
            }

            ++$stationRemotes;
            if ('' === trim($remote->url)) {
                $issues[] = $this->issue(
                    'critical',
                    'remote-streams',
                    __('Remote Streams'),
                    $remote->display_name,
                    __('Remote relay has no URL configured.'),
                    $timestamp,
                    'state'
                );
            } else {
                ++$configured;
            }
        }

        foreach ($station->playlists as $playlist) {
            if (
                !$playlist instanceof StationPlaylist
                || !$playlist->is_enabled
                || PlaylistSources::RemoteUrl !== $playlist->source
            ) {
                continue;
            }

            ++$remotePlaylists;
            if (empty(trim((string)$playlist->remote_url))) {
                $issues[] = $this->issue(
                    'critical',
                    'remote-streams',
                    __('Remote Streams'),
                    $playlist->name,
                    __('Remote URL playlist cannot execute because its URL is empty.'),
                    $timestamp,
                    'state'
                );
            } else {
                ++$configured;
            }
        }

        $total = $stationRemotes + $remotePlaylists;

        return $this->feature(
            'remote-streams',
            __('Remote Streams'),
            0 === $total ? 'inactive' : ($configured === $total ? 'healthy' : 'critical'),
            0 === $total ? __('No remote sources configured') : __('Remote source connectivity signals'),
            __('Checks remote relay and remote playlist configuration, plus recent runtime errors from station logs.'),
            sprintf('%d sources', $total),
            'state+logs'
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function buildRssPodcastsFeature(Station $station, array &$issues, int $timestamp): array
    {
        /** @var list<Podcast> $podcasts */
        $podcasts = $this->em->createQuery(
            <<<'DQL'
                SELECT p FROM App\Entity\Podcast p
                WHERE p.storage_location = :storageLocation
            DQL
        )->setParameter('storageLocation', $station->podcasts_storage_location)
            ->getResult();

        $imports = 0;
        $automatic = 0;
        $episodes = 0;

        foreach ($podcasts as $podcast) {
            $episodes += $podcast->episodes->count();

            if (PodcastSources::Import !== $podcast->source || !$podcast->is_enabled) {
                continue;
            }

            ++$imports;
            if (!$podcast->auto_import_enabled) {
                continue;
            }

            ++$automatic;
            if (empty(trim((string)$podcast->feed_url))) {
                $issues[] = $this->issue(
                    'critical',
                    'rss-podcasts',
                    __('RSS Podcasts'),
                    $podcast->title,
                    __('Automatic RSS import is enabled but no feed URL is configured.'),
                    $timestamp,
                    'state'
                );
            }
        }

        return $this->feature(
            'rss-podcasts',
            __('RSS Podcasts'),
            0 === $imports ? 'inactive' : 'healthy',
            0 === $imports ? __('No enabled RSS imports') : __('Feed import execution'),
            __('Shows RSS import configuration and promotes fetch, XML and episode download failures from runtime logs.'),
            sprintf('%d feeds · %d episodes', $automatic, $episodes),
            'state+logs'
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function buildAiFeature(Station $station, array &$issues, int $timestamp): array
    {
        /** @var list<AiDj> $djs */
        $djs = $this->em->createQuery(
            <<<'DQL'
                SELECT d FROM App\Entity\AiDj d
                WHERE d.station = :station
            DQL
        )->setParameter('station', $station)
            ->getResult();

        $enabled = 0;
        $scheduled = 0;
        foreach ($djs as $dj) {
            if (!$dj->isEnabled()) {
                continue;
            }

            ++$enabled;
            if ($dj->getSchedules()->count() > 0) {
                ++$scheduled;
            } else {
                $issues[] = $this->issue(
                    'warning',
                    'ai-automation',
                    __('AI Automation'),
                    $dj->getName(),
                    __('AI DJ is enabled but has no scheduled shift.'),
                    $timestamp,
                    'state'
                );
            }
        }

        if (null !== $station->ai_news_last_error && '' !== trim($station->ai_news_last_error)) {
            $issues[] = $this->issue(
                'warning',
                'ai-automation',
                __('AI Automation'),
                __('AI Newscaster reported an error'),
                $this->sanitize($station, $station->ai_news_last_error),
                $station->ai_news_last_generation_time?->getTimestamp() ?? $timestamp,
                'state'
            );
        }

        return $this->feature(
            'ai-automation',
            __('AI Automation'),
            0 === $enabled && null === $station->ai_news_last_generation_time ? 'inactive' : 'healthy',
            0 === $enabled ? __('AI newscast and DJ signals') : __('AI DJ shift execution'),
            __('Checks enabled AI DJs, shift scheduling, AI Newscaster state and recent runtime failures.'),
            sprintf('%d/%d DJs scheduled', $scheduled, $enabled),
            'state+logs'
        );
    }

    /** @param array<string, mixed> $runtimeHealth @return array<string, mixed> */
    private function buildAirCheckFeature(Station $station, array $runtimeHealth): array
    {
        $enabled = $station->backend_config->aircheck_enabled;
        $running = (int)($runtimeHealth['running'] ?? 0);
        $total = (int)($runtimeHealth['total'] ?? 0);

        return $this->feature(
            'aircheck',
            __('AirCheck'),
            !$enabled ? 'inactive' : ($running === $total ? 'healthy' : 'critical'),
            $enabled ? __('Automatic health recovery enabled') : __('Automatic recovery disabled'),
            __('Tracks station recovery actions and shared infrastructure state transitions.'),
            $enabled ? sprintf('%d/%d healthy', $running, $total) : 'Off',
            'live+events'
        );
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function buildEventIssues(Station $station, array $events): array
    {
        $issues = [];
        foreach ($events as $event) {
            $level = (string)($event['level'] ?? 'INFO');
            if (!in_array($level, ['WARNING', 'ERROR'], true)) {
                continue;
            }

            $featureKey = $this->mapFeatureKey((string)($event['feature'] ?? ''));
            $label = $this->featureLabel($featureKey);
            $detail = $this->formatContext((array)($event['context'] ?? []));

            $issues[] = $this->issue(
                'ERROR' === $level ? 'critical' : 'warning',
                $featureKey,
                $label,
                $this->sanitize($station, (string)($event['message'] ?? __('Diagnostic event'))),
                $this->sanitize($station, $detail),
                (int)($event['timestamp'] ?? time()),
                'event'
            );
        }

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function scanRuntimeLogSignals(Station $station): array
    {
        $logTypes = [
            ...$this->adapters->getBackendAdapter($station)?->getLogTypes($station) ?? [],
            ...$this->adapters->getFrontendAdapter($station)?->getLogTypes($station) ?? [],
        ];

        $patterns = [
            'rss-podcasts' => [
                'failed to fetch podcast feed',
                'invalid xml in podcast feed',
                'failed to download episode media',
                'failed to attach media to episode',
            ],
            'remote-streams' => [
                'playlist remote url',
                'remote playlist',
                'remote relay',
            ],
            'playlists' => [
                'no valid playlists detected',
                'duplicate prevention yielded no playable song',
                'rotation goal blocked all tracks',
            ],
            'ai-automation' => [
                'ai dj: failed',
                'ai dj error',
                'ai news',
                'ai newscaster',
            ],
            'clock-wheels' => [
                'clock wheel',
                'top-of-hour',
                'top of hour',
            ],
            'smart-blocks' => ['smart block'],
            'linear-log' => ['linear log'],
            'station-services' => ['liquidsoap', 'icecast', 'shoutcast'],
        ];

        $issues = [];
        $seen = [];

        foreach ($logTypes as $logType) {
            $path = $logType->path ?? null;
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $tail = $this->readTail($path, self::RAW_LOG_TAIL_BYTES);
            if ('' === $tail) {
                continue;
            }

            $lines = preg_split('/\R/', $tail) ?: [];
            foreach (array_reverse($lines) as $line) {
                $line = trim($line);
                if ('' === $line || !$this->looksLikeFailure($line)) {
                    continue;
                }

                $normalized = strtolower($line);
                foreach ($patterns as $featureKey => $featurePatterns) {
                    if (!$this->containsAny($normalized, $featurePatterns)) {
                        continue;
                    }

                    $sanitized = $this->sanitize($station, $line);
                    $dedupeKey = $featureKey . ':' . md5($sanitized);
                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }
                    $seen[$dedupeKey] = true;

                    $issues[] = $this->issue(
                        str_contains($normalized, ' error') || str_contains($normalized, '[error]')
                            || str_contains($normalized, 'failed') || str_contains($normalized, 'exception')
                            ? 'critical'
                            : 'warning',
                        $featureKey,
                        $this->featureLabel($featureKey),
                        __('Recent runtime log signal'),
                        mb_substr($sanitized, 0, 360),
                        (int)(filemtime($path) ?: time()),
                        'service_log'
                    );

                    if (count($issues) >= 16) {
                        return $issues;
                    }
                    break;
                }
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $features
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    private function applyIssueStatusToFeatures(array $features, array $issues): array
    {
        $severityByFeature = [];
        $countByFeature = [];

        foreach ($issues as $issue) {
            $key = (string)$issue['feature_key'];
            $severity = (string)$issue['severity'];
            ++$countByFeature[$key];

            if ('critical' === $severity) {
                $severityByFeature[$key] = 'critical';
            } elseif (!isset($severityByFeature[$key])) {
                $severityByFeature[$key] = 'warning';
            }
        }

        foreach ($features as &$feature) {
            $key = (string)$feature['key'];
            $feature['issues'] = $countByFeature[$key] ?? 0;

            if ('inactive' === $feature['status']) {
                continue;
            }

            if ('critical' === ($severityByFeature[$key] ?? null)) {
                $feature['status'] = 'critical';
            } elseif ('warning' === ($severityByFeature[$key] ?? null) && 'critical' !== $feature['status']) {
                $feature['status'] = 'warning';
            }
        }
        unset($feature);

        return $features;
    }

    /** @param array<string, mixed> $runtimeHealth @return list<array<string, mixed>> */
    private function normalizeServices(Station $station, array $runtimeHealth): array
    {
        $services = [];
        foreach (['station_services', 'system_services'] as $group) {
            foreach ((array)($runtimeHealth[$group] ?? []) as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $configured = true === ($service['configured'] ?? false);
                $running = $service['running'] ?? null;
                $isStationService = 'station' === ($service['scope'] ?? null);

                if (!$configured || ($isStationService && (!$station->is_enabled || !$station->has_started))) {
                    $status = 'inactive';
                } elseif (true === $running) {
                    $status = 'healthy';
                } elseif (false === $running) {
                    $status = 'critical';
                } else {
                    $status = 'inactive';
                }

                $services[] = [
                    'key' => (string)($service['key'] ?? 'service'),
                    'name' => (string)($service['name'] ?? __('Service')),
                    'description' => (string)($service['description'] ?? ''),
                    'scope' => (string)($service['scope'] ?? 'system'),
                    'recovery' => (string)($service['recovery'] ?? ''),
                    'status' => $status,
                    'running' => $running,
                ];
            }
        }

        return $services;
    }

    /** @param list<array<string, mixed>> $events @return list<array<string, int>> */
    private function buildTimeline(array $events): array
    {
        $currentBucket = intdiv(time(), 3600) * 3600;
        $buckets = [];
        for ($i = self::WINDOW_HOURS - 1; $i >= 0; --$i) {
            $timestamp = $currentBucket - ($i * 3600);
            $buckets[$timestamp] = [
                'timestamp' => $timestamp,
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
            ];
        }

        foreach ($events as $event) {
            $timestamp = (int)($event['timestamp'] ?? 0);
            $bucket = intdiv($timestamp, 3600) * 3600;
            if (!isset($buckets[$bucket])) {
                continue;
            }

            $key = match ((string)($event['level'] ?? 'INFO')) {
                'ERROR' => 'critical',
                'WARNING' => 'warning',
                default => 'info',
            };
            ++$buckets[$bucket][$key];
        }

        return array_values($buckets);
    }

    /** @param list<array<string, mixed>> $features @return array<string, int> */
    private function buildDistribution(array $features): array
    {
        $distribution = [
            'healthy' => 0,
            'warning' => 0,
            'critical' => 0,
            'inactive' => 0,
        ];

        foreach ($features as $feature) {
            $status = (string)($feature['status'] ?? 'inactive');
            if (isset($distribution[$status])) {
                ++$distribution[$status];
            }
        }

        return $distribution;
    }

    /** @param list<array<string, mixed>> $features */
    private function calculateHealthScore(array $features): int
    {
        $weights = [
            'healthy' => 100,
            'warning' => 65,
            'critical' => 15,
        ];
        $scores = [];

        foreach ($features as $feature) {
            $status = (string)($feature['status'] ?? 'inactive');
            if (isset($weights[$status])) {
                $scores[] = $weights[$status];
            }
        }

        return [] === $scores ? 100 : (int)round(array_sum($scores) / count($scores));
    }

    /** @param list<array<string, mixed>> $features @param list<array<string, mixed>> $services */
    private function calculateOverallStatus(array $features, array $services): string
    {
        foreach ([...$features, ...$services] as $item) {
            if ('critical' === ($item['status'] ?? null)) {
                return 'critical';
            }
        }

        foreach ($features as $feature) {
            if ('warning' === ($feature['status'] ?? null)) {
                return 'warning';
            }
        }

        return 'healthy';
    }

    /** @param list<array<string, mixed>> $issues @return list<array<string, mixed>> */
    private function sortAndLimitIssues(array $issues): array
    {
        usort(
            $issues,
            static function (array $a, array $b): int {
                $severity = ['critical' => 2, 'warning' => 1];
                $severityCompare = ($severity[$b['severity']] ?? 0) <=> ($severity[$a['severity']] ?? 0);
                return 0 !== $severityCompare
                    ? $severityCompare
                    : ((int)$b['timestamp'] <=> (int)$a['timestamp']);
            }
        );

        return array_slice($issues, 0, self::MAX_RECENT_ISSUES);
    }

    /** @return array<string, mixed> */
    private function feature(
        string $key,
        string $label,
        string $status,
        string $headline,
        string $detail,
        string $metric,
        string $basis,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'headline' => $headline,
            'detail' => $detail,
            'metric' => $metric,
            'basis' => $basis,
            'issues' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function issue(
        string $severity,
        string $featureKey,
        string $feature,
        string $title,
        string $detail,
        int $timestamp,
        string $source,
    ): array {
        return [
            'severity' => $severity,
            'feature_key' => $featureKey,
            'feature' => $feature,
            'title' => $title,
            'detail' => trim($detail),
            'timestamp' => $timestamp,
            'source' => $source,
        ];
    }

    private function mapFeatureKey(string $feature): string
    {
        $normalized = strtolower(trim($feature));

        return match (true) {
            str_contains($normalized, 'clock') => 'clock-wheels',
            str_contains($normalized, 'linear') => 'linear-log',
            str_contains($normalized, 'smart') => 'smart-blocks',
            str_contains($normalized, 'aircheck') => 'aircheck',
            str_contains($normalized, 'podcast') || str_contains($normalized, 'rss') => 'rss-podcasts',
            str_contains($normalized, 'remote') => 'remote-streams',
            str_contains($normalized, 'playlist') => 'playlists',
            str_contains($normalized, 'ai') => 'ai-automation',
            default => 'station-services',
        };
    }

    private function featureLabel(string $key): string
    {
        return match ($key) {
            'clock-wheels' => __('Clock Wheels'),
            'linear-log' => __('Linear Log'),
            'smart-blocks' => __('Smart Blocks'),
            'aircheck' => __('AirCheck'),
            'rss-podcasts' => __('RSS Podcasts'),
            'remote-streams' => __('Remote Streams'),
            'playlists' => __('Playlists'),
            'ai-automation' => __('AI Automation'),
            default => __('Station Services'),
        };
    }

    /** @param array<string, mixed> $context */
    private function formatContext(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (!is_scalar($value) && null !== $value) {
                continue;
            }

            $parts[] = sprintf('%s: %s', str_replace('_', ' ', (string)$key), null === $value ? 'null' : (string)$value);
            if (count($parts) >= 4) {
                break;
            }
        }

        return implode(' · ', $parts);
    }

    private function sanitize(Station $station, string $value): string
    {
        $filtered = str_replace($station->getFilteredPasswords(), '(PASSWORD)', $value);
        return preg_replace('/\s+/', ' ', trim($filtered)) ?: '';
    }

    private function readTail(string $path, int $maxBytes): string
    {
        try {
            $size = filesize($path);
            if (false === $size || 0 === $size) {
                return '';
            }

            $stream = fopen($path, 'rb');
            if (false === $stream) {
                return '';
            }

            $bytes = min($maxBytes, $size);
            if ($size > $bytes) {
                fseek($stream, -$bytes, SEEK_END);
            }

            $contents = stream_get_contents($stream) ?: '';
            fclose($stream);

            if ($size > $bytes) {
                $firstLineBreak = strpos($contents, "\n");
                if (false !== $firstLineBreak) {
                    $contents = substr($contents, $firstLineBreak + 1);
                }
            }

            return $contents;
        } catch (Throwable) {
            return '';
        }
    }

    private function looksLikeFailure(string $line): bool
    {
        $line = strtolower($line);
        return $this->containsAny($line, [
            '[error]',
            '[warning]',
            ' error:',
            ' warning:',
            ' failed',
            ' failure',
            ' exception',
            ' unable to ',
            ' cannot ',
        ]);
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
