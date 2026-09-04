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
use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class StationDiagnosticsDashboard
{
    private const int DEFAULT_WINDOW_HOURS = 24;
    private const int MAX_WINDOW_HOURS = 2160;
    private const int RAW_LOG_TAIL_BYTES = 768 * 1024;
    private const int MAX_RECENT_ISSUES = 40;

    public function __construct(
        private EntityManagerInterface $em,
        private AirCheckHealthMonitor $airCheckHealthMonitor,
        private StationHealthService $stationHealthService,
        private StationDiagnostics $diagnostics,
        private Adapters $adapters,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSnapshot(
        Station $station,
        ?int $startTimestamp = null,
        ?int $endTimestamp = null,
        ?string $featureFilter = null,
    ): array {
        $generatedAt = time();
        $endTimestamp ??= $generatedAt;
        $endTimestamp = min($endTimestamp, $generatedAt + 300);
        $startTimestamp ??= $endTimestamp - (self::DEFAULT_WINDOW_HOURS * 3600);
        $startTimestamp = max($startTimestamp, $endTimestamp - (self::MAX_WINDOW_HOURS * 3600));
        if ($startTimestamp >= $endTimestamp) {
            $startTimestamp = $endTimestamp - (self::DEFAULT_WINDOW_HOURS * 3600);
        }

        $windowHours = max(1, (int)ceil(($endTimestamp - $startTimestamp) / 3600));
        $historyHours = max(1, (int)ceil(($generatedAt - $startTimestamp) / 3600));
        $historyHours = min(self::MAX_WINDOW_HOURS, $historyHours + 1);

        $events = array_values(array_filter(
            $this->diagnostics->getRecentEvents($station, $historyHours, 20000),
            static fn(array $event): bool => (int)($event['timestamp'] ?? 0) >= $startTimestamp
                && (int)($event['timestamp'] ?? 0) <= $endTimestamp
        ));

        $runtimeHealth = $this->airCheckHealthMonitor->getSnapshot($station);
        $stationHealth = $this->stationHealthService->getReport($station);
        $execution = $this->getExecutionCounters($station, $startTimestamp, $endTimestamp);

        $issues = [];
        $features = [
            $this->buildStationServicesFeature($station, $runtimeHealth, $issues, $generatedAt),
            $this->buildMediaLibraryFeature($station, $stationHealth, $issues, $generatedAt),
            $this->buildPlaylistsFeature($station, $issues, $generatedAt, $execution),
            $this->buildPlaylistGroupsFeature($station, $issues, $generatedAt, $execution),
            $this->buildClockWheelsFeature($station, $issues, $generatedAt, $execution),
            $this->buildSmartBlocksFeature($station, $execution),
            $this->buildLinearLogFeature($station),
            $this->buildRemoteStreamsFeature($station, $issues, $generatedAt, $execution),
            $this->buildRssPodcastsFeature($station, $issues, $generatedAt, $execution),
            $this->buildShowsFeature($station, $issues, $generatedAt),
            $this->buildAiDjFeature($station, $issues, $generatedAt),
            $this->buildAiNewsFeature($station, $issues, $generatedAt),
            $this->buildAirCheckFeature($station, $runtimeHealth),
            $this->buildTopOfHourFeature($station, $stationHealth, $issues, $generatedAt),
            $this->buildPlayoutControlsFeature($station),
            $this->buildCrossfadeProfilesFeature($station),
            $this->buildBroadcastOutputsFeature($stationHealth),
            $this->buildRequestsFeature($station, $execution),
        ];

        $availableFeatures = array_map(
            static fn(array $feature): array => [
                'key' => (string)$feature['key'],
                'label' => (string)$feature['label'],
                'category' => (string)$feature['category'],
            ],
            $features
        );

        $eventSignals = $this->buildEventSignals($station, $events);
        $runtimeSignals = $this->scanRuntimeLogSignals($station, $startTimestamp, $endTimestamp);
        $signals = [...$eventSignals, ...$runtimeSignals];

        foreach ($signals as $signal) {
            if ('success' === ($signal['severity'] ?? null)) {
                continue;
            }
            $issues[] = $signal;
        }

        $features = $this->enrichFeatures(
            $features,
            $issues,
            $signals,
            $startTimestamp,
            $endTimestamp,
            $generatedAt
        );
        $issues = $this->sortAndLimitIssues($issues);
        $services = $this->normalizeServices($station, $runtimeHealth);

        $validFeatureKeys = array_column($availableFeatures, 'key');
        if (null !== $featureFilter && !in_array($featureFilter, $validFeatureKeys, true)) {
            $featureFilter = null;
        }

        if (null !== $featureFilter && '' !== $featureFilter) {
            $features = array_values(array_filter(
                $features,
                static fn(array $feature): bool => $feature['key'] === $featureFilter
            ));
            $issues = array_values(array_filter(
                $issues,
                static fn(array $issue): bool => $issue['feature_key'] === $featureFilter
            ));
            if ('station-services' !== $featureFilter) {
                $services = [];
            }
        }

        $distribution = $this->buildDistribution($features);
        $healthScore = $this->calculateHealthScore($features);
        $overallStatus = $this->calculateOverallStatus($features, $services);
        $successfulSignals = array_sum(array_map(
            static fn(array $feature): int => (int)($feature['stats']['successes'] ?? 0),
            $features
        ));
        $failureSignals = array_sum(array_map(
            static fn(array $feature): int => (int)($feature['stats']['failures'] ?? 0),
            $features
        ));
        $warningSignals = array_sum(array_map(
            static fn(array $feature): int => (int)($feature['stats']['warnings'] ?? 0),
            $features
        ));

        return [
            'generated_at' => $generatedAt,
            'window_hours' => $windowHours,
            'window' => [
                'start' => $startTimestamp,
                'end' => $endTimestamp,
                'hours' => $windowHours,
                'bucket_seconds' => $this->bucketSeconds($windowHours),
            ],
            'filter' => [
                'feature' => $featureFilter,
            ],
            'available_features' => $availableFeatures,
            'overall_status' => $overallStatus,
            'health_score' => $healthScore,
            'counts' => [
                'critical' => $distribution['critical'],
                'warning' => $distribution['warning'],
                'healthy' => $distribution['healthy'],
                'inactive' => $distribution['inactive'],
                'recent_events' => count($events),
                'active_issues' => count($issues),
                'services_running' => (int)($runtimeHealth['running'] ?? 0),
                'services_total' => (int)($runtimeHealth['total'] ?? 0),
                'successes' => $successfulSignals,
                'failures' => $failureSignals,
                'warning_signals' => $warningSignals,
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
            'timeline' => $this->buildTimeline($signals, $startTimestamp, $endTimestamp),
            'features' => $features,
            'services' => $services,
            'recent_issues' => $issues,
        ];
    }

    /**
     * @return array<string, array{successes:int,warnings:int,failures:int}>
     */
    private function getExecutionCounters(Station $station, int $start, int $end): array
    {
        $counters = [];
        foreach ($this->featureDefinitions() as $key => $definition) {
            $counters[$key] = ['successes' => 0, 'warnings' => 0, 'failures' => 0];
        }

        $startDate = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('UTC'));
        $endDate = (new DateTimeImmutable('@' . $end))->setTimezone(new DateTimeZone('UTC'));

        $counters['playlists']['successes'] = $this->countDql(
            'SELECT COUNT(h.id) FROM App\\Entity\\SongHistory h WHERE h.station = :station AND h.timestamp_start BETWEEN :start AND :end AND h.playlist IS NOT NULL',
            ['station' => $station, 'start' => $startDate, 'end' => $endDate]
        );
        $counters['smart-blocks']['successes'] = $this->countDql(
            'SELECT COUNT(h.id) FROM App\\Entity\\SongHistory h JOIN h.playlist p WHERE h.station = :station AND h.timestamp_start BETWEEN :start AND :end AND p.is_smart_block = true',
            ['station' => $station, 'start' => $startDate, 'end' => $endDate]
        );
        $counters['remote-streams']['successes'] = $this->countDql(
            'SELECT COUNT(h.id) FROM App\\Entity\\SongHistory h JOIN h.playlist p WHERE h.station = :station AND h.timestamp_start BETWEEN :start AND :end AND p.source = :source',
            [
                'station' => $station,
                'start' => $startDate,
                'end' => $endDate,
                'source' => PlaylistSources::RemoteUrl->value,
            ]
        );
        $counters['playlist-groups']['successes'] = $this->countDql(
            'SELECT COUNT(h.id) FROM App\\Entity\\SongHistory h WHERE h.station = :station AND h.timestamp_start BETWEEN :start AND :end AND h.playlist_chain IS NOT NULL',
            ['station' => $station, 'start' => $startDate, 'end' => $endDate]
        );
        $counters['requests']['successes'] = $this->countDql(
            'SELECT COUNT(h.id) FROM App\\Entity\\SongHistory h WHERE h.station = :station AND h.timestamp_start BETWEEN :start AND :end AND h.request IS NOT NULL',
            ['station' => $station, 'start' => $startDate, 'end' => $endDate]
        );
        $counters['rss-podcasts']['successes'] = $this->countDql(
            'SELECT COUNT(e.id) FROM App\\Entity\\PodcastEpisode e JOIN e.podcast p WHERE p.storage_location = :storage AND p.source = :source AND e.created_at BETWEEN :start AND :end',
            [
                'storage' => $station->podcasts_storage_location,
                'source' => PodcastSources::Import->value,
                'start' => $start,
                'end' => $end,
            ]
        );

        try {
            /** @var list<array{kind:mixed,cnt:mixed}> $rows */
            $rows = $this->em->createQuery(
                <<<'DQL'
                    SELECT e.event_kind AS kind, COUNT(e.id) AS cnt
                    FROM App\Entity\ClockWheelEvent e
                    WHERE e.station = :station AND e.event_timestamp BETWEEN :start AND :end
                    GROUP BY e.event_kind
                DQL
            )->setParameter('station', $station)
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate)
                ->getArrayResult();

            foreach ($rows as $row) {
                $kind = $row['kind'] ?? null;
                $kindValue = $kind instanceof BackedEnum ? $kind->value : (string)$kind;
                $count = (int)($row['cnt'] ?? 0);
                if ('track_queued' === $kindValue) {
                    $counters['clock-wheels']['successes'] += $count;
                } elseif ('deferred' === $kindValue) {
                    $counters['clock-wheels']['warnings'] += $count;
                } elseif ('fallback' === $kindValue) {
                    $counters['clock-wheels']['failures'] += $count;
                }
            }
        } catch (Throwable) {
        }

        return $counters;
    }

    /** @param array<string, mixed> $params */
    private function countDql(string $dql, array $params): int
    {
        try {
            $query = $this->em->createQuery($dql);
            foreach ($params as $key => $value) {
                $query->setParameter($key, $value);
            }
            return (int)$query->getSingleScalarResult();
        } catch (Throwable) {
            return 0;
        }
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
                __('Restart broadcasting for pending configuration changes to take effect.'),
                $timestamp,
                'state'
            );
        }

        $status = !$station->is_enabled || !$station->has_started
            ? 'inactive'
            : ($running === count($configured) ? 'healthy' : 'critical');

        return $this->feature(
            'station-services', __('Station Services'), 'runtime', $status,
            $station->has_started ? __('Broadcast engine runtime') : __('Station is stopped'),
            __('Backend, frontend and shared infrastructure are checked live when this page loads.'),
            sprintf('%d/%d online', $running, count($configured)), 'live', $running,
            [['label' => __('Configured services'), 'value' => count($configured)], ['label' => __('Running services'), 'value' => $running]]
        );
    }

    /** @param list<array<string, mixed>> $issues @return array<string, mixed> */
    private function buildMediaLibraryFeature(Station $station, object $health, array &$issues, int $timestamp): array
    {
        $tracks = (int)($health->media_tracks ?? 0);
        $blocked = (int)($health->do_not_play_count ?? 0);
        if ($station->supportsAutoDjQueue() && 0 === $tracks) {
            $issues[] = $this->issue('critical', 'media-library', __('Media Library'), __('No media is available'), __('AutoDJ is enabled but the media library has no tracks.'), $timestamp, 'state');
        }

        return $this->feature(
            'media-library', __('Media Library'), 'content', 0 === $tracks ? 'inactive' : 'healthy',
            0 === $tracks ? __('No media tracks') : __('Media inventory ready'),
            __('Tracks available media and files excluded from playout.'),
            sprintf('%d tracks', $tracks), 'state', $tracks > 0 ? 1 : 0,
            [['label' => __('Media tracks'), 'value' => $tracks], ['label' => __('Do not play'), 'value' => $blocked]]
        );
    }

    /** @param list<array<string, mixed>> $issues @param array<string, array<string,int>> $execution @return array<string, mixed> */
    private function buildPlaylistsFeature(Station $station, array &$issues, int $timestamp, array $execution): array
    {
        $enabled = 0;
        $ready = 0;
        foreach ($station->playlists as $playlist) {
            if (!$playlist instanceof StationPlaylist || !$playlist->is_enabled) {
                continue;
            }
            if (in_array($playlist->source, [PlaylistSources::Playlists, PlaylistSources::RemoteUrl], true)) {
                continue;
            }
            ++$enabled;
            if (PlaylistSources::Songs === $playlist->source && 0 === $playlist->media_items->count()) {
                $issues[] = $this->issue('warning', 'playlists', __('Playlists'), $playlist->name, __('Enabled playlist has no media assigned.'), $timestamp, 'state');
                continue;
            }
            ++$ready;
        }

        if ($station->supportsAutoDjQueue() && 0 === $ready && $enabled > 0) {
            $issues[] = $this->issue('critical', 'playlists', __('Playlists'), __('No content-ready playlists'), __('Enabled rotation playlists cannot currently provide content.'), $timestamp, 'state');
        }

        return $this->feature(
            'playlists', __('Playlists'), 'playout', 0 === $enabled ? 'inactive' : ($ready === $enabled ? 'healthy' : 'warning'),
            0 === $enabled ? __('No enabled rotation playlists') : __('Rotation source readiness'),
            __('Checks playlist content readiness and observed playback in the selected time range.'),
            sprintf('%d/%d ready', $ready, $enabled), 'state+history', $ready,
            [['label' => __('Enabled'), 'value' => $enabled], ['label' => __('Ready'), 'value' => $ready], ['label' => __('Observed plays'), 'value' => $execution['playlists']['successes'] ?? 0]],
            $execution['playlists'] ?? []
        );
    }

    /** @param list<array<string, mixed>> $issues @param array<string, array<string,int>> $execution @return array<string, mixed> */
    private function buildPlaylistGroupsFeature(Station $station, array &$issues, int $timestamp, array $execution): array
    {
        $enabled = 0;
        $ready = 0;
        $members = 0;
        foreach ($station->playlists as $playlist) {
            if (!$playlist instanceof StationPlaylist || !$playlist->is_enabled || PlaylistSources::Playlists !== $playlist->source) {
                continue;
            }
            ++$enabled;
            $count = $playlist->playlists->count();
            $members += $count;
            if (0 === $count) {
                $issues[] = $this->issue('warning', 'playlist-groups', __('Playlist Groups'), $playlist->name, __('Enabled playlist group has no member playlists.'), $timestamp, 'state');
            } else {
                ++$ready;
            }
        }

        return $this->feature(
            'playlist-groups', __('Playlist Groups'), 'playout', 0 === $enabled ? 'inactive' : ($enabled === $ready ? 'healthy' : 'warning'),
            0 === $enabled ? __('No enabled playlist groups') : __('Grouped rotation readiness'),
            __('Checks group membership and detects playback routed through playlist groups.'),
            sprintf('%d groups · %d members', $enabled, $members), 'state+history', $ready,
            [['label' => __('Groups'), 'value' => $enabled], ['label' => __('Member playlists'), 'value' => $members], ['label' => __('Observed grouped plays'), 'value' => $execution['playlist-groups']['successes'] ?? 0]],
            $execution['playlist-groups'] ?? []
        );
    }

    /** @param list<array<string, mixed>> $issues @param array<string, array<string,int>> $execution @return array<string, mixed> */
    private function buildClockWheelsFeature(Station $station, array &$issues, int $timestamp, array $execution): array
    {
        $active = 0;
        $ready = 0;
        $slots = 0;
        foreach ($station->clock_wheels as $wheel) {
            if (!$wheel instanceof StationClockWheel || !$wheel->is_active) {
                continue;
            }
            ++$active;
            $slotCount = $wheel->slots->count();
            $slots += $slotCount;
            if (0 === $slotCount) {
                $issues[] = $this->issue('warning', 'clock-wheels', __('Clock Wheels'), $wheel->name, __('Active Clock Wheel has no playout slots.'), $timestamp, 'state');
            } else {
                ++$ready;
            }
        }

        $runtime = $execution['clock-wheels'] ?? [];
        return $this->feature(
            'clock-wheels', __('Clock Wheels'), 'scheduling', 0 === $active ? 'inactive' : ($active === $ready ? 'healthy' : 'warning'),
            0 === $active ? __('No active Clock Wheels') : __('Clock execution and fallback health'),
            __('Combines wheel configuration with queued-track, deferral and fallback execution records.'),
            sprintf('%d wheels · %d slots', $active, $slots), 'state+events', $ready,
            [['label' => __('Tracks queued'), 'value' => $runtime['successes'] ?? 0], ['label' => __('Deferred'), 'value' => $runtime['warnings'] ?? 0], ['label' => __('Fallbacks'), 'value' => $runtime['failures'] ?? 0]],
            $runtime
        );
    }

    /** @param array<string, array<string,int>> $execution @return array<string, mixed> */
    private function buildSmartBlocksFeature(Station $station, array $execution): array
    {
        $enabled = 0;
        foreach ($station->playlists as $playlist) {
            if ($playlist instanceof StationPlaylist && $playlist->is_enabled && $playlist->is_smart_block) {
                ++$enabled;
            }
        }
        $runtime = $execution['smart-blocks'] ?? [];
        return $this->feature(
            'smart-blocks', __('Smart Blocks'), 'playout', $enabled > 0 ? 'healthy' : 'inactive',
            $enabled > 0 ? __('Dynamic playlist synchronization') : __('No enabled Smart Blocks'),
            __('Shows active Smart Blocks, observed plays and synchronization/runtime failures.'),
            sprintf('%d active', $enabled), 'state+history+events', $enabled,
            [['label' => __('Active Smart Blocks'), 'value' => $enabled], ['label' => __('Observed plays'), 'value' => $runtime['successes'] ?? 0]],
            $runtime
        );
    }

    /** @return array<string, mixed> */
    private function buildLinearLogFeature(Station $station): array
    {
        $enabled = $station->backend_config->linear_log_enabled;
        return $this->feature(
            'linear-log', __('Linear Log'), 'scheduling', $enabled ? 'healthy' : 'inactive',
            $enabled ? __('Rolling playout plan enabled') : __('Linear Log disabled'),
            $enabled ? __('Build failures and successful diagnostic events are tracked in the selected range.') : __('This station is not configured to build a rolling Linear Log.'),
            $enabled ? sprintf('%dh plan', $station->backend_config->linear_log_hours) : __('Off'), 'events', $enabled ? 1 : 0,
            [['label' => __('Configured hours'), 'value' => $enabled ? $station->backend_config->linear_log_hours : 0]]
        );
    }

    /** @param list<array<string, mixed>> $issues @param array<string, array<string,int>> $execution @return array<string, mixed> */
    private function buildRemoteStreamsFeature(Station $station, array &$issues, int $timestamp, array $execution): array
    {
        $relays = 0;
        $webStreams = 0;
        $ready = 0;
        foreach ($station->remotes as $remote) {
            if (!$remote instanceof StationRemote) {
                continue;
            }
            ++$relays;
            if ('' === trim((string)$remote->url)) {
                $issues[] = $this->issue('critical', 'remote-streams', __('Web / Remote Streams'), (string)$remote->display_name, __('Remote relay has no URL configured.'), $timestamp, 'state');
            } else {
                ++$ready;
            }
        }
        foreach ($station->playlists as $playlist) {
            if (!$playlist instanceof StationPlaylist || !$playlist->is_enabled || PlaylistSources::RemoteUrl !== $playlist->source) {
                continue;
            }
            ++$webStreams;
            if ('' === trim((string)$playlist->remote_url)) {
                $issues[] = $this->issue('critical', 'remote-streams', __('Web / Remote Streams'), $playlist->name, __('Web stream cannot execute because its URL is empty.'), $timestamp, 'state');
            } else {
                ++$ready;
            }
        }
        $total = $relays + $webStreams;
        $runtime = $execution['remote-streams'] ?? [];
        return $this->feature(
            'remote-streams', __('Web / Remote Streams'), 'external', 0 === $total ? 'inactive' : ($ready === $total ? 'healthy' : 'critical'),
            0 === $total ? __('No remote sources configured') : __('Remote source execution and connectivity'),
            __('Covers Web / Remote Stream playlists and remote relays, including URL configuration, observed plays and runtime fetch/connect failures.'),
            sprintf('%d web streams · %d relays', $webStreams, $relays), 'state+history+logs', $ready,
            [['label' => __('Web streams'), 'value' => $webStreams], ['label' => __('Remote relays'), 'value' => $relays], ['label' => __('Observed web-stream plays'), 'value' => $runtime['successes'] ?? 0]],
            $runtime
        );
    }

    /** @param list<array<string, mixed>> $issues @param array<string, array<string,int>> $execution @return array<string, mixed> */
    private function buildRssPodcastsFeature(Station $station, array &$issues, int $timestamp, array $execution): array
    {
        /** @var list<Podcast> $podcasts */
        $podcasts = $this->em->createQuery(
            'SELECT p FROM App\\Entity\\Podcast p WHERE p.storage_location = :storageLocation'
        )->setParameter('storageLocation', $station->podcasts_storage_location)->getResult();

        $imports = 0;
        $automatic = 0;
        $feedsReady = 0;
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
            if ('' === trim((string)$podcast->feed_url)) {
                $issues[] = $this->issue('critical', 'rss-podcasts', __('RSS Podcasts'), $podcast->title, __('Automatic RSS import is enabled but no feed URL is configured.'), $timestamp, 'state');
            } else {
                ++$feedsReady;
            }
        }

        $runtime = $execution['rss-podcasts'] ?? [];
        $status = 0 === $imports ? 'inactive' : ($feedsReady === $automatic ? 'healthy' : 'critical');
        return $this->feature(
            'rss-podcasts', __('RSS Podcasts'), 'external', $status,
            0 === $imports ? __('No enabled RSS imports') : __('RSS feed import execution'),
            __('Covers automatic RSS feed configuration, imported episodes and feed/XML/media-download errors from runtime logs.'),
            sprintf('%d auto feeds · %d episodes', $automatic, $episodes), 'state+database+logs', $feedsReady,
            [['label' => __('Import podcasts'), 'value' => $imports], ['label' => __('Automatic feeds'), 'value' => $automatic], ['label' => __('Feeds ready'), 'value' => $feedsReady], ['label' => __('Episodes imported in range'), 'value' => $runtime['successes'] ?? 0]],
            $runtime
        );
    }

    /** @param list<array<string, mixed>> $issues @return array<string, mixed> */
    private function buildShowsFeature(Station $station, array &$issues, int $timestamp): array
    {
        $shows = (array)$station->backend_config->feature_shows;
        $enabled = 0;
        $ready = 0;
        $segments = 0;
        $schedules = 0;
        foreach ($shows as $show) {
            if (!is_array($show) || false === ($show['enabled'] ?? true)) {
                continue;
            }
            ++$enabled;
            $name = (string)($show['name'] ?? __('Untitled Show'));
            $showSegments = count((array)($show['segments'] ?? []));
            $showSchedules = count((array)($show['schedules'] ?? []));
            $segments += $showSegments;
            $schedules += $showSchedules;
            if (0 === $showSegments) {
                $issues[] = $this->issue('critical', 'shows', __('Shows'), $name, __('Enabled show has no segments to play.'), $timestamp, 'state');
                continue;
            }
            if (0 === $showSchedules) {
                $issues[] = $this->issue('warning', 'shows', __('Shows'), $name, __('Enabled show has no schedule.'), $timestamp, 'state');
                continue;
            }
            ++$ready;
        }

        return $this->feature(
            'shows', __('Shows'), 'scheduling', 0 === $enabled ? 'inactive' : ($enabled === $ready ? 'healthy' : 'warning'),
            0 === $enabled ? __('No enabled Shows') : __('Structured show readiness'),
            __('Checks that enabled Shows have both playable segments and schedules, while runtime show errors are promoted from logs.'),
            sprintf('%d shows · %d segments', $enabled, $segments), 'state+logs', $ready,
            [['label' => __('Enabled Shows'), 'value' => $enabled], ['label' => __('Segments'), 'value' => $segments], ['label' => __('Schedules'), 'value' => $schedules], ['label' => __('Ready Shows'), 'value' => $ready]]
        );
    }

    /** @param list<array<string, mixed>> $issues @return array<string, mixed> */
    private function buildAiDjFeature(Station $station, array &$issues, int $timestamp): array
    {
        /** @var list<AiDj> $djs */
        $djs = $this->em->createQuery('SELECT d FROM App\\Entity\\AiDj d WHERE d.station = :station')
            ->setParameter('station', $station)->getResult();
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
                $issues[] = $this->issue('warning', 'ai-dj', __('AI DJ'), $dj->getName(), __('AI DJ is enabled but has no scheduled shift.'), $timestamp, 'state');
            }
        }

        return $this->feature(
            'ai-dj', __('AI DJ'), 'automation', 0 === $enabled ? 'inactive' : ($enabled === $scheduled ? 'healthy' : 'warning'),
            0 === $enabled ? __('No enabled AI DJs') : __('AI DJ shift execution'),
            __('Checks DJ enablement and shift schedules and promotes generation/playout failures from diagnostics and service logs.'),
            sprintf('%d/%d DJs scheduled', $scheduled, $enabled), 'state+logs', $scheduled,
            [['label' => __('Enabled DJs'), 'value' => $enabled], ['label' => __('Scheduled DJs'), 'value' => $scheduled]]
        );
    }

    /** @param list<array<string, mixed>> $issues @return array<string, mixed> */
    private function buildAiNewsFeature(Station $station, array &$issues, int $timestamp): array
    {
        $enabled = (bool)$station->backend_config->ai_news_enabled;
        $lastTime = $station->ai_news_last_generation_time?->getTimestamp();
        $lastStatus = trim((string)($station->ai_news_last_generation_status ?? ''));
        $lastError = trim((string)($station->ai_news_last_error ?? ''));
        if ($enabled && '' !== $lastError) {
            $issues[] = $this->issue('warning', 'ai-news', __('AI News'), __('Latest AI News generation reported an error'), $this->sanitize($station, $lastError), $lastTime ?? $timestamp, 'state');
        }

        return $this->feature(
            'ai-news', __('AI News'), 'automation', !$enabled ? 'inactive' : ('' === $lastError ? 'healthy' : 'warning'),
            !$enabled ? __('AI News disabled') : __('AI bulletin generation'),
            __('Tracks enabled state, latest generation result and AI Newscaster runtime errors.'),
            $enabled ? ($lastStatus !== '' ? $lastStatus : __('Enabled')) : __('Off'), 'state+logs', $enabled && '' === $lastError ? 1 : 0,
            [['label' => __('Enabled'), 'value' => $enabled ? __('Yes') : __('No')], ['label' => __('Last status'), 'value' => $lastStatus !== '' ? $lastStatus : __('No status yet')], ['label' => __('Last generation'), 'value' => $lastTime ?? 0]]
        );
    }

    /** @param array<string, mixed> $runtimeHealth @return array<string, mixed> */
    private function buildAirCheckFeature(Station $station, array $runtimeHealth): array
    {
        $enabled = $station->backend_config->aircheck_enabled;
        $running = (int)($runtimeHealth['running'] ?? 0);
        $total = (int)($runtimeHealth['total'] ?? 0);
        return $this->feature(
            'aircheck', __('AirCheck'), 'monitoring', !$enabled ? 'inactive' : ($running === $total ? 'healthy' : 'critical'),
            $enabled ? __('Automatic health recovery enabled') : __('Automatic recovery disabled'),
            __('Tracks health checks, recovery actions and shared infrastructure state transitions.'),
            $enabled ? sprintf('%d/%d services healthy', $running, $total) : __('Off'), 'live+events', $enabled ? max(0, $running) : 0,
            [['label' => __('Services running'), 'value' => $running], ['label' => __('Services checked'), 'value' => $total]]
        );
    }

    /** @param list<array<string, mixed>> $issues @return array<string, mixed> */
    private function buildTopOfHourFeature(Station $station, object $health, array &$issues, int $timestamp): array
    {
        $enabled = (bool)$station->backend_config->top_of_hour_id_enabled;
        $compliance = $health->legal_id_compliance_percent ?? null;
        $status = 'inactive';
        $passed = 0;
        if ($enabled) {
            if (null === $compliance) {
                $status = 'warning';
                $issues[] = $this->issue('warning', 'top-of-hour', __('Top of Hour ID'), __('No compliance sample is available yet'), __('The feature is enabled, but there is not enough recent legal-ID execution data to calculate compliance.'), $timestamp, 'state');
            } elseif ($compliance < 90.0) {
                $status = 'critical';
                $issues[] = $this->issue('critical', 'top-of-hour', __('Top of Hour ID'), __('Top-of-hour compliance is below 90%'), sprintf(__('Current 7-day compliance is %.1f%%.'), $compliance), $timestamp, 'state');
            } elseif ($compliance < 100.0) {
                $status = 'warning';
                $issues[] = $this->issue('warning', 'top-of-hour', __('Top of Hour ID'), __('Some legal IDs missed the configured tolerance'), sprintf(__('Current 7-day compliance is %.1f%%.'), $compliance), $timestamp, 'state');
                $passed = 1;
            } else {
                $status = 'healthy';
                $passed = 1;
            }
        }

        return $this->feature(
            'top-of-hour', __('Top of Hour ID'), 'scheduling', $status,
            !$enabled ? __('Top of Hour ID disabled') : __('Legal ID compliance'),
            __('Monitors the station-wide top-of-hour legal ID requirement and surfaces hard-clock/legal-ID failures.'),
            null === $compliance ? ($enabled ? __('Awaiting data') : __('Off')) : sprintf('%.1f%% compliance', $compliance), 'state+events', $passed,
            [['label' => __('Enabled'), 'value' => $enabled ? __('Yes') : __('No')], ['label' => __('7-day compliance'), 'value' => null === $compliance ? __('No data') : sprintf('%.1f%%', $compliance)]]
        );
    }

    /** @return array<string, mixed> */
    private function buildPlayoutControlsFeature(Station $station): array
    {
        $config = $station->backend_config;
        $hardClock = $this->configBool($config, 'hard_clock_enabled');
        $stretch = $this->configBool($config, 'stretch_squeeze_enabled');
        $duck = $this->configBool($config, 'smart_duck_enabled');
        $enabledCount = (int)$hardClock + (int)$stretch + (int)$duck;
        return $this->feature(
            'playout-controls', __('Playout Controls'), 'playout', $enabledCount > 0 ? 'healthy' : 'inactive',
            $enabledCount > 0 ? __('Advanced playout controls configured') : __('Advanced playout controls are inactive'),
            __('Covers hard-clock trigger, station-wide Stretch / Squeeze and Smart Ducking configuration plus related runtime failures.'),
            sprintf('%d/3 enabled', $enabledCount), 'state+logs', $enabledCount,
            [['label' => __('Hard clock'), 'value' => $hardClock ? __('Enabled') : __('Disabled')], ['label' => __('Stretch / Squeeze'), 'value' => $stretch ? __('Enabled') : __('Disabled')], ['label' => __('Smart Ducking'), 'value' => $duck ? __('Enabled') : __('Disabled')]]
        );
    }

    /** @return array<string, mixed> */
    private function buildCrossfadeProfilesFeature(Station $station): array
    {
        $profiles = $this->configArray($station->backend_config, 'crossfade_profiles');
        $matrix = $this->configArray($station->backend_config, 'content_type_crossfade_matrix');
        return $this->feature(
            'crossfade-profiles', __('Crossfade Profiles'), 'audio', [] === $profiles ? 'inactive' : 'healthy',
            [] === $profiles ? __('No custom crossfade profiles') : __('Crossfade profiles configured'),
            __('Tracks custom crossfade profile/matrix configuration and promotes crossfade-related runtime errors.'),
            sprintf('%d profiles', count($profiles)), 'state+logs', count($profiles),
            [['label' => __('Profiles'), 'value' => count($profiles)], ['label' => __('Matrix rules'), 'value' => count($matrix)]]
        );
    }

    /** @return array<string, mixed> */
    private function buildBroadcastOutputsFeature(object $health): array
    {
        $mounts = (array)($health->stream_mounts ?? []);
        return $this->feature(
            'broadcast-outputs', __('Broadcast Outputs'), 'runtime', [] === $mounts ? 'inactive' : 'healthy',
            [] === $mounts ? __('No stream mounts detected') : __('Stream mount configuration'),
            __('Shows configured station output mounts while Icecast/Liquidsoap failures remain visible in the service diagnostics.'),
            sprintf('%d mounts', count($mounts)), 'state+live', count($mounts),
            [['label' => __('Configured mounts'), 'value' => count($mounts)]]
        );
    }

    /** @param array<string, array<string,int>> $execution @return array<string, mixed> */
    private function buildRequestsFeature(Station $station, array $execution): array
    {
        $enabled = (bool)$station->enable_requests;
        $runtime = $execution['requests'] ?? [];
        return $this->feature(
            'requests', __('Listener Requests'), 'playout', $enabled ? 'healthy' : 'inactive',
            $enabled ? __('Request playback enabled') : __('Listener requests disabled'),
            __('Tracks request availability and successfully played listener requests in the selected range.'),
            $enabled ? sprintf('%d plays', $runtime['successes'] ?? 0) : __('Off'), 'state+history', $enabled ? 1 : 0,
            [['label' => __('Observed request plays'), 'value' => $runtime['successes'] ?? 0]],
            $runtime
        );
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function buildEventSignals(Station $station, array $events): array
    {
        $signals = [];
        foreach ($events as $event) {
            $level = (string)($event['level'] ?? 'INFO');
            $featureKey = $this->mapFeatureKey((string)($event['feature'] ?? ''));
            $severity = match ($level) {
                'ERROR' => 'critical',
                'WARNING' => 'warning',
                default => 'success',
            };
            $signals[] = $this->issue(
                $severity,
                $featureKey,
                $this->featureLabel($featureKey),
                $this->sanitize($station, (string)($event['message'] ?? __('Diagnostic event'))),
                $this->sanitize($station, $this->formatContext((array)($event['context'] ?? []))),
                (int)($event['timestamp'] ?? time()),
                'diagnostics'
            );
        }
        return $signals;
    }

    /** @return list<array<string, mixed>> */
    private function scanRuntimeLogSignals(Station $station, int $start, int $end): array
    {
        $logTypes = [
            ...$this->adapters->getBackendAdapter($station)?->getLogTypes($station) ?? [],
            ...$this->adapters->getFrontendAdapter($station)?->getLogTypes($station) ?? [],
        ];
        $patterns = [
            'rss-podcasts' => ['podcast feed', 'rss feed', 'invalid xml', 'episode media', 'podcast import'],
            'remote-streams' => ['remote playlist', 'remote url', 'remote relay', 'web stream', 'playlist remote url'],
            'shows' => ['show scheduler', 'show segment', 'station show'],
            'playlists' => ['no valid playlists', 'duplicate prevention', 'rotation goal', 'playlist'],
            'playlist-groups' => ['playlist group'],
            'ai-dj' => ['ai dj'],
            'ai-news' => ['ai news', 'newscaster'],
            'clock-wheels' => ['clock wheel'],
            'top-of-hour' => ['top-of-hour', 'top of hour', 'legal id', 'hard clock'],
            'playout-controls' => ['stretch', 'squeeze', 'smart duck', 'ducking'],
            'crossfade-profiles' => ['crossfade'],
            'smart-blocks' => ['smart block'],
            'linear-log' => ['linear log'],
            'requests' => ['listener request', 'song request'],
            'station-services' => ['liquidsoap', 'icecast', 'shoutcast', 'broadcast frontend', 'broadcast backend'],
        ];

        $signals = [];
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
                $timestamp = $this->extractLogTimestamp($line, $station, (int)(filemtime($path) ?: time()));
                if ($timestamp < $start || $timestamp > $end) {
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
                        break;
                    }
                    $seen[$dedupeKey] = true;
                    $signals[] = $this->issue(
                        $this->isCriticalLogLine($normalized) ? 'critical' : 'warning',
                        $featureKey,
                        $this->featureLabel($featureKey),
                        __('Runtime log failure'),
                        mb_substr($sanitized, 0, 460),
                        $timestamp,
                        'service_log'
                    );
                    if (count($signals) >= 80) {
                        return $signals;
                    }
                    break;
                }
            }
        }
        return $signals;
    }

    /**
     * @param list<array<string, mixed>> $features
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $signals
     * @return list<array<string, mixed>>
     */
    private function enrichFeatures(
        array $features,
        array $issues,
        array $signals,
        int $start,
        int $end,
        int $generatedAt,
    ): array {
        foreach ($features as &$feature) {
            $key = (string)$feature['key'];
            $featureIssues = array_values(array_filter($issues, static fn(array $issue): bool => $issue['feature_key'] === $key));
            $featureSignals = array_values(array_filter($signals, static fn(array $signal): bool => $signal['feature_key'] === $key));

            $critical = count(array_filter($featureIssues, static fn(array $issue): bool => 'critical' === $issue['severity']));
            $warning = count(array_filter($featureIssues, static fn(array $issue): bool => 'warning' === $issue['severity']));
            $infoSuccesses = count(array_filter($featureSignals, static fn(array $signal): bool => 'success' === $signal['severity']));

            $executionStats = (array)($feature['execution_stats'] ?? []);
            $executionSuccesses = (int)($executionStats['successes'] ?? 0) + $infoSuccesses;
            $executionWarnings = (int)($executionStats['warnings'] ?? 0);
            $executionFailures = (int)($executionStats['failures'] ?? 0);
            $checksPassed = (int)($feature['checks_passed'] ?? 0);
            $successes = $executionSuccesses + $checksPassed;
            $warnings = $warning + $executionWarnings;
            $failures = $critical + $executionFailures;
            $total = $successes + $warnings + $failures;
            $successRate = $total > 0 ? round(($successes / $total) * 100, 1) : null;

            if ('inactive' !== $feature['status']) {
                if ($failures > 0) {
                    $feature['status'] = 'critical';
                } elseif ($warnings > 0 && 'critical' !== $feature['status']) {
                    $feature['status'] = 'warning';
                }
            }

            $feature['issues'] = count($featureIssues);
            $feature['stats'] = [
                'successes' => $successes,
                'successful_executions' => $executionSuccesses,
                'checks_passed' => $checksPassed,
                'warnings' => $warnings,
                'failures' => $failures,
                'observations' => $total,
                'success_rate' => $successRate,
            ];
            $feature['top_problems'] = array_slice($this->sortIssues($featureIssues), 0, 4);
            $feature['activity'] = $this->buildFeatureTimeline($featureSignals, $featureIssues, $start, $end);
            $feature['drilldown'] = $this->buildDrilldown($feature, $featureSignals, $featureIssues, $generatedAt);
            $feature['last_success_at'] = $this->latestSignalTimestamp($featureSignals, 'success')
                ?? ($checksPassed > 0 ? $generatedAt : null);
            $feature['last_failure_at'] = $this->latestIssueTimestamp($featureIssues);
            unset($feature['checks_passed'], $feature['execution_stats']);
        }
        unset($feature);
        return $features;
    }

    /** @param array<string,mixed> $feature @param list<array<string,mixed>> $signals @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function buildDrilldown(array $feature, array $signals, array $issues, int $generatedAt): array
    {
        $rows = [];
        foreach ((array)($feature['details'] ?? []) as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $rows[] = [
                'state' => 'success',
                'title' => (string)($detail['label'] ?? __('Check')),
                'detail' => (string)($detail['value'] ?? ''),
                'timestamp' => $generatedAt,
                'source' => 'state',
            ];
        }
        foreach (array_reverse($signals) as $signal) {
            if ('success' !== ($signal['severity'] ?? null)) {
                continue;
            }
            $rows[] = [
                'state' => 'success',
                'title' => (string)$signal['title'],
                'detail' => (string)$signal['detail'],
                'timestamp' => (int)$signal['timestamp'],
                'source' => (string)$signal['source'],
            ];
            if (count($rows) >= 8) {
                break;
            }
        }
        foreach (array_slice($this->sortIssues($issues), 0, 6) as $issue) {
            $rows[] = [
                'state' => 'critical' === $issue['severity'] ? 'failure' : 'warning',
                'title' => (string)$issue['title'],
                'detail' => (string)$issue['detail'],
                'timestamp' => (int)$issue['timestamp'],
                'source' => (string)$issue['source'],
            ];
        }
        return array_slice($rows, 0, 12);
    }

    /** @param list<array<string,mixed>> $signals @param list<array<string,mixed>> $issues @return list<array<string,int>> */
    private function buildFeatureTimeline(array $signals, array $issues, int $start, int $end): array
    {
        $points = $this->emptyBuckets($start, $end, ['success', 'warning', 'failure']);
        foreach ($signals as $signal) {
            $timestamp = (int)($signal['timestamp'] ?? 0);
            $key = match ((string)($signal['severity'] ?? 'success')) {
                'critical' => 'failure',
                'warning' => 'warning',
                default => 'success',
            };
            $this->incrementBucket($points, $timestamp, $key);
        }
        foreach ($issues as $issue) {
            if ('diagnostics' === ($issue['source'] ?? null)) {
                continue;
            }
            $this->incrementBucket(
                $points,
                (int)($issue['timestamp'] ?? 0),
                'critical' === ($issue['severity'] ?? null) ? 'failure' : 'warning'
            );
        }
        return array_values($points);
    }

    /** @param list<array<string,mixed>> $signals @return list<array<string,int>> */
    private function buildTimeline(array $signals, int $start, int $end): array
    {
        $points = $this->emptyBuckets($start, $end, ['info', 'warning', 'critical']);
        foreach ($signals as $signal) {
            $key = match ((string)($signal['severity'] ?? 'success')) {
                'critical' => 'critical',
                'warning' => 'warning',
                default => 'info',
            };
            $this->incrementBucket($points, (int)($signal['timestamp'] ?? 0), $key);
        }
        return array_values($points);
    }

    /** @param list<string> $keys @return array<int,array<string,int>> */
    private function emptyBuckets(int $start, int $end, array $keys): array
    {
        $hours = max(1, (int)ceil(($end - $start) / 3600));
        $bucketSeconds = $this->bucketSeconds($hours);
        $first = intdiv($start, $bucketSeconds) * $bucketSeconds;
        $last = intdiv($end, $bucketSeconds) * $bucketSeconds;
        $points = [];
        for ($timestamp = $first; $timestamp <= $last; $timestamp += $bucketSeconds) {
            $point = ['timestamp' => $timestamp];
            foreach ($keys as $key) {
                $point[$key] = 0;
            }
            $points[$timestamp] = $point;
        }
        return $points;
    }

    /** @param array<int,array<string,int>> $points */
    private function incrementBucket(array &$points, int $timestamp, string $key): void
    {
        if ([] === $points) {
            return;
        }
        $timestamps = array_keys($points);
        $bucketSeconds = count($timestamps) > 1 ? $timestamps[1] - $timestamps[0] : 3600;
        $bucket = intdiv($timestamp, $bucketSeconds) * $bucketSeconds;
        if (isset($points[$bucket][$key])) {
            ++$points[$bucket][$key];
        }
    }

    private function bucketSeconds(int $windowHours): int
    {
        return $windowHours <= 48 ? 3600 : 86400;
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

    /** @param list<array<string,mixed>> $features @return array<string,int> */
    private function buildDistribution(array $features): array
    {
        $distribution = ['healthy' => 0, 'warning' => 0, 'critical' => 0, 'inactive' => 0];
        foreach ($features as $feature) {
            $status = (string)($feature['status'] ?? 'inactive');
            if (isset($distribution[$status])) {
                ++$distribution[$status];
            }
        }
        return $distribution;
    }

    /** @param list<array<string,mixed>> $features */
    private function calculateHealthScore(array $features): int
    {
        $weights = ['healthy' => 100, 'warning' => 65, 'critical' => 15];
        $scores = [];
        foreach ($features as $feature) {
            $status = (string)($feature['status'] ?? 'inactive');
            if (isset($weights[$status])) {
                $scores[] = $weights[$status];
            }
        }
        return [] === $scores ? 100 : (int)round(array_sum($scores) / count($scores));
    }

    /** @param list<array<string,mixed>> $features @param list<array<string,mixed>> $services */
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

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function sortAndLimitIssues(array $issues): array
    {
        return array_slice($this->sortIssues($issues), 0, self::MAX_RECENT_ISSUES);
    }

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function sortIssues(array $issues): array
    {
        usort($issues, static function (array $a, array $b): int {
            $severity = ['critical' => 2, 'warning' => 1, 'success' => 0];
            $cmp = ($severity[$b['severity']] ?? 0) <=> ($severity[$a['severity']] ?? 0);
            return 0 !== $cmp ? $cmp : ((int)$b['timestamp'] <=> (int)$a['timestamp']);
        });
        return $issues;
    }

    /** @param array<string,int> $executionStats @param list<array{label:string,value:mixed}> $details @return array<string,mixed> */
    private function feature(
        string $key,
        string $label,
        string $category,
        string $status,
        string $headline,
        string $detail,
        string $metric,
        string $basis,
        int $checksPassed = 0,
        array $details = [],
        array $executionStats = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'status' => $status,
            'headline' => $headline,
            'detail' => $detail,
            'metric' => $metric,
            'basis' => $basis,
            'checks_passed' => max(0, $checksPassed),
            'execution_stats' => [
                'successes' => (int)($executionStats['successes'] ?? 0),
                'warnings' => (int)($executionStats['warnings'] ?? 0),
                'failures' => (int)($executionStats['failures'] ?? 0),
            ],
            'details' => $details,
            'issues' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function issue(string $severity, string $featureKey, string $feature, string $title, string $detail, int $timestamp, string $source): array
    {
        return [
            'severity' => $severity,
            'feature_key' => $featureKey,
            'feature' => $feature,
            'title' => trim($title),
            'detail' => trim($detail),
            'timestamp' => $timestamp,
            'source' => $source,
        ];
    }

    /** @return array<string,array{label:string,category:string}> */
    private function featureDefinitions(): array
    {
        return [
            'station-services' => ['label' => __('Station Services'), 'category' => 'runtime'],
            'media-library' => ['label' => __('Media Library'), 'category' => 'content'],
            'playlists' => ['label' => __('Playlists'), 'category' => 'playout'],
            'playlist-groups' => ['label' => __('Playlist Groups'), 'category' => 'playout'],
            'clock-wheels' => ['label' => __('Clock Wheels'), 'category' => 'scheduling'],
            'smart-blocks' => ['label' => __('Smart Blocks'), 'category' => 'playout'],
            'linear-log' => ['label' => __('Linear Log'), 'category' => 'scheduling'],
            'remote-streams' => ['label' => __('Web / Remote Streams'), 'category' => 'external'],
            'rss-podcasts' => ['label' => __('RSS Podcasts'), 'category' => 'external'],
            'shows' => ['label' => __('Shows'), 'category' => 'scheduling'],
            'ai-dj' => ['label' => __('AI DJ'), 'category' => 'automation'],
            'ai-news' => ['label' => __('AI News'), 'category' => 'automation'],
            'aircheck' => ['label' => __('AirCheck'), 'category' => 'monitoring'],
            'top-of-hour' => ['label' => __('Top of Hour ID'), 'category' => 'scheduling'],
            'playout-controls' => ['label' => __('Playout Controls'), 'category' => 'playout'],
            'crossfade-profiles' => ['label' => __('Crossfade Profiles'), 'category' => 'audio'],
            'broadcast-outputs' => ['label' => __('Broadcast Outputs'), 'category' => 'runtime'],
            'requests' => ['label' => __('Listener Requests'), 'category' => 'playout'],
        ];
    }

    private function mapFeatureKey(string $feature): string
    {
        $normalized = strtolower(trim($feature));
        return match (true) {
            str_contains($normalized, 'podcast') || str_contains($normalized, 'rss') => 'rss-podcasts',
            str_contains($normalized, 'remote') || str_contains($normalized, 'web stream') => 'remote-streams',
            str_contains($normalized, 'playlist group') => 'playlist-groups',
            str_contains($normalized, 'playlist') => 'playlists',
            str_contains($normalized, 'clock') && !str_contains($normalized, 'hard') => 'clock-wheels',
            str_contains($normalized, 'top') || str_contains($normalized, 'legal id') || str_contains($normalized, 'hard clock') => 'top-of-hour',
            str_contains($normalized, 'linear') => 'linear-log',
            str_contains($normalized, 'smart block') => 'smart-blocks',
            str_contains($normalized, 'show') => 'shows',
            str_contains($normalized, 'ai news') || str_contains($normalized, 'newscast') => 'ai-news',
            str_contains($normalized, 'ai dj') || str_contains($normalized, 'dj') => 'ai-dj',
            str_contains($normalized, 'aircheck') => 'aircheck',
            str_contains($normalized, 'stretch') || str_contains($normalized, 'squeeze') || str_contains($normalized, 'duck') => 'playout-controls',
            str_contains($normalized, 'crossfade') => 'crossfade-profiles',
            str_contains($normalized, 'request') => 'requests',
            default => 'station-services',
        };
    }

    private function featureLabel(string $key): string
    {
        $definition = $this->featureDefinitions()[$key] ?? null;
        return null !== $definition ? $definition['label'] : __('Station Services');
    }

    /** @param array<string,mixed> $context */
    private function formatContext(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (!is_scalar($value) && null !== $value) {
                continue;
            }
            $parts[] = sprintf('%s: %s', str_replace('_', ' ', (string)$key), null === $value ? 'null' : (string)$value);
            if (count($parts) >= 5) {
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
        return $this->containsAny(strtolower($line), ['[error]', '[warning]', ' error:', ' warning:', ' failed', ' failure', ' exception', ' unable to ', ' cannot ', ' timeout', 'timed out']);
    }

    private function isCriticalLogLine(string $line): bool
    {
        return $this->containsAny($line, ['[error]', ' error:', ' failed', ' failure', ' exception', ' unable to ', ' cannot ', ' timeout', 'timed out']);
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

    private function extractLogTimestamp(string $line, Station $station, int $fallback): int
    {
        $patterns = [
            '/\[(\d{4}-\d{2}-\d{2}T[^]]+)]/',
            '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/',
            '/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $line, $matches)) {
                continue;
            }
            try {
                return (new DateTimeImmutable($matches[1], $station->getTimezoneObject()))->getTimestamp();
            } catch (Throwable) {
            }
        }
        return $fallback;
    }

    private function configBool(object $config, string $property, bool $default = false): bool
    {
        if (!property_exists($config, $property)) {
            return $default;
        }
        try {
            return (bool)$config->{$property};
        } catch (Throwable) {
            return $default;
        }
    }

    /** @return array<mixed> */
    private function configArray(object $config, string $property): array
    {
        if (!property_exists($config, $property)) {
            return [];
        }
        try {
            $value = $config->{$property};
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $signals */
    private function latestSignalTimestamp(array $signals, string $severity): ?int
    {
        $timestamps = array_map(
            static fn(array $signal): int => (int)$signal['timestamp'],
            array_filter($signals, static fn(array $signal): bool => $signal['severity'] === $severity)
        );
        return [] === $timestamps ? null : max($timestamps);
    }

    /** @param list<array<string,mixed>> $issues */
    private function latestIssueTimestamp(array $issues): ?int
    {
        if ([] === $issues) {
            return null;
        }
        return max(array_map(static fn(array $issue): int => (int)$issue['timestamp'], $issues));
    }
}
