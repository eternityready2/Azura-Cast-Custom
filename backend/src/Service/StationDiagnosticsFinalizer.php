<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClockWheelEvent;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PodcastSources;
use App\Entity\Station;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Final consistency pass for station diagnostics.
 *
 * The collector intentionally gathers evidence from several independent sources.
 * This layer reconciles evidence that represents the same operation, attributes
 * station-level legal-ID decisions separately from Clock Wheels, and adds
 * database-backed executions to the activity timeline.
 */
final readonly class StationDiagnosticsFinalizer
{
    private const array DUPLICATE_CLOCK_MESSAGES = [
        'Clock Wheel slot was deferred.',
        'Clock Wheel used fallback behavior.',
        'Top-of-hour legal ID used fallback behavior.',
    ];

    public function __construct(
        private StationDiagnosticsDashboardView $dashboard,
        private EntityManagerInterface $em,
        private StationDiagnostics $diagnostics,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSnapshot(
        Station $station,
        ?int $startTimestamp = null,
        ?int $endTimestamp = null,
        ?string $featureFilter = null,
    ): array {
        $snapshot = $this->dashboard->getSnapshot(
            $station,
            $startTimestamp,
            $endTimestamp,
            $featureFilter,
        );

        $window = (array)($snapshot['window'] ?? []);
        $start = (int)($window['start'] ?? $startTimestamp ?? (time() - 86400));
        $end = (int)($window['end'] ?? $endTimestamp ?? time());
        $bucketSeconds = max(3600, (int)($window['bucket_seconds'] ?? 3600));
        $activeFilter = $snapshot['filter']['feature'] ?? $featureFilter;
        $activeFilter = is_string($activeFilter) && '' !== $activeFilter ? $activeFilter : null;

        $clockCounts = $this->getClockWheelCounts($station, $start, $end);
        $clockIssues = $this->getClockWheelIssues($station, $start, $end);
        $duplicateEvents = $this->getDuplicateClockDiagnostics($station, $start, $end);

        $features = (array)($snapshot['features'] ?? []);
        foreach ($features as &$feature) {
            if (!is_array($feature)) {
                continue;
            }

            $key = (string)($feature['key'] ?? '');
            if ('clock-wheels' === $key) {
                $this->correctClockWheelFeature(
                    $feature,
                    $clockCounts['clock-wheels'],
                    $clockCounts['top-of-hour'],
                    $duplicateEvents,
                    array_values(array_filter(
                        $clockIssues,
                        static fn(array $issue): bool => 'clock-wheels' === ($issue['feature_key'] ?? null),
                    )),
                );
            } elseif ('top-of-hour' === $key) {
                $this->correctTopOfHourFeature(
                    $feature,
                    $clockCounts['top-of-hour'],
                    array_values(array_filter(
                        $clockIssues,
                        static fn(array $issue): bool => 'top-of-hour' === ($issue['feature_key'] ?? null),
                    )),
                );
            }
        }
        unset($feature);
        $snapshot['features'] = array_values($features);

        $recentIssues = array_values(array_filter(
            (array)($snapshot['recent_issues'] ?? []),
            fn(mixed $issue): bool => is_array($issue) && !$this->isDuplicateClockDiagnosticIssue($issue),
        ));
        foreach ($clockIssues as $issue) {
            if (null !== $activeFilter && $activeFilter !== ($issue['feature_key'] ?? null)) {
                continue;
            }
            $recentIssues[] = $issue;
        }
        $snapshot['recent_issues'] = array_slice($this->sortIssues($recentIssues), 0, 40);

        $snapshot['timeline'] = $this->buildFinalTimeline(
            $snapshot,
            $station,
            $start,
            $end,
            $bucketSeconds,
            $activeFilter,
            $duplicateEvents,
        );

        $this->recalculateSummary($snapshot);

        return $snapshot;
    }

    /**
     * @return array{
     *     clock-wheels:array{successes:int,warnings:int,failures:int},
     *     top-of-hour:array{successes:int,warnings:int,failures:int}
     * }
     */
    private function getClockWheelCounts(Station $station, int $start, int $end): array
    {
        $counts = [
            'clock-wheels' => ['successes' => 0, 'warnings' => 0, 'failures' => 0],
            'top-of-hour' => ['successes' => 0, 'warnings' => 0, 'failures' => 0],
        ];

        try {
            $startDate = $this->utcDate($start);
            $endDate = $this->utcDate($end);
            $rows = $this->em->createQuery(
                <<<'DQL'
                    SELECT e.event_kind AS kind,
                           e.anchor_type AS anchor,
                           IDENTITY(e.clock_wheel) AS wheel_id,
                           COUNT(e.id) AS cnt
                    FROM App\Entity\ClockWheelEvent e
                    WHERE e.station = :station AND e.event_timestamp BETWEEN :start AND :end
                    GROUP BY e.event_kind, e.anchor_type, e.clock_wheel
                DQL
            )
                ->setParameter('station', $station)
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate)
                ->getArrayResult();

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $kind = $row['kind'] ?? null;
                $kind = $kind instanceof BackedEnum ? $kind->value : (string)$kind;
                $isLegalId = 'legal_id' === (string)($row['anchor'] ?? '')
                    && null === ($row['wheel_id'] ?? null);
                $feature = $isLegalId ? 'top-of-hour' : 'clock-wheels';
                $count = (int)($row['cnt'] ?? 0);

                if ('track_queued' === $kind) {
                    $counts[$feature]['successes'] += $count;
                } elseif ('deferred' === $kind) {
                    $counts[$feature]['warnings'] += $count;
                } elseif ('fallback' === $kind) {
                    $counts[$feature]['failures'] += $count;
                }
            }
        } catch (Throwable) {
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    private function getClockWheelIssues(Station $station, int $start, int $end): array
    {
        try {
            /** @var iterable<ClockWheelEvent> $events */
            $events = $this->em->createQuery(
                <<<'DQL'
                    SELECT e
                    FROM App\Entity\ClockWheelEvent e
                    WHERE e.station = :station
                      AND e.event_timestamp BETWEEN :start AND :end
                      AND e.event_kind IN (:kinds)
                    ORDER BY e.event_timestamp DESC
                DQL
            )
                ->setParameter('station', $station)
                ->setParameter('start', $this->utcDate($start))
                ->setParameter('end', $this->utcDate($end))
                ->setParameter('kinds', ['deferred', 'fallback'])
                ->setMaxResults(80)
                ->toIterable();

            $issues = [];
            foreach ($events as $event) {
                $kind = $event->event_kind->value;
                $isLegalId = 'legal_id' === $event->anchor_type && null === $event->clock_wheel;
                $featureKey = $isLegalId ? 'top-of-hour' : 'clock-wheels';
                $featureLabel = $isLegalId ? __('Top of Hour ID') : __('Clock Wheels');
                $reason = $event->fallback_reason?->value ?? __('unspecified');
                $wheelName = $event->clock_wheel?->name;

                if ($isLegalId) {
                    $title = __('Top-of-hour legal ID used fallback behavior');
                    $detail = sprintf(__('Fallback reason: %s.'), $reason);
                } elseif ('deferred' === $kind) {
                    $title = __('Clock Wheel slot was deferred');
                    $detail = null !== $wheelName
                        ? sprintf(__('%s deferred a slot. Reason: %s.'), $wheelName, $reason)
                        : sprintf(__('A Clock Wheel slot was deferred. Reason: %s.'), $reason);
                } else {
                    $title = __('Clock Wheel used fallback behavior');
                    $detail = null !== $wheelName
                        ? sprintf(__('%s used fallback behavior. Reason: %s.'), $wheelName, $reason)
                        : sprintf(__('Clock Wheel fallback reason: %s.'), $reason);
                }

                $issues[] = [
                    'severity' => 'fallback' === $kind ? 'critical' : 'warning',
                    'feature_key' => $featureKey,
                    'feature' => $featureLabel,
                    'title' => $title,
                    'detail' => $detail,
                    'timestamp' => $event->event_timestamp->getTimestamp(),
                    'source' => 'database_execution',
                ];
            }

            return $issues;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{message:string,timestamp:int}> */
    private function getDuplicateClockDiagnostics(Station $station, int $start, int $end): array
    {
        $hours = max(1, min(2160, (int)ceil((time() - $start) / 3600) + 1));
        $result = [];

        try {
            foreach ($this->diagnostics->getRecentEvents($station, $hours, 20000) as $event) {
                $timestamp = (int)($event['timestamp'] ?? 0);
                if ($timestamp < $start || $timestamp > $end) {
                    continue;
                }
                $message = trim((string)($event['message'] ?? ''));
                if (in_array($message, self::DUPLICATE_CLOCK_MESSAGES, true)) {
                    $result[] = ['message' => $message, 'timestamp' => $timestamp];
                }
            }
        } catch (Throwable) {
        }

        return $result;
    }

    /** @param array<string, mixed> $feature @param array{successes:int,warnings:int,failures:int} $clockCounts @param array{successes:int,warnings:int,failures:int} $topCounts @param list<array{message:string,timestamp:int}> $duplicates @param list<array<string,mixed>> $databaseIssues */
    private function correctClockWheelFeature(
        array &$feature,
        array $clockCounts,
        array $topCounts,
        array $duplicates,
        array $databaseIssues,
    ): void {
        $stats = (array)($feature['stats'] ?? []);
        $duplicateWarnings = count($duplicates);

        $executions = max(
            $clockCounts['successes'],
            max(0, (int)($stats['successful_executions'] ?? 0) - $topCounts['successes']),
        );
        $warnings = max(
            $clockCounts['warnings'],
            max(0, (int)($stats['warnings'] ?? 0) - $duplicateWarnings),
        );
        $failures = max(
            $clockCounts['failures'],
            max(0, (int)($stats['failures'] ?? 0) - $topCounts['failures']),
        );

        $this->writeStats($feature, $executions, $warnings, $failures);
        $this->replaceClockProblems($feature, $databaseIssues);
        $this->applyOutcomeStatus($feature);

        foreach ((array)($feature['details'] ?? []) as &$detail) {
            if (!is_array($detail)) {
                continue;
            }
            $label = strtolower((string)($detail['label'] ?? ''));
            if (str_contains($label, 'tracks queued')) {
                $detail['value'] = $clockCounts['successes'];
            } elseif (str_contains($label, 'deferred')) {
                $detail['value'] = $clockCounts['warnings'];
            } elseif (str_contains($label, 'fallback')) {
                $detail['value'] = $clockCounts['failures'];
            }
        }
        unset($detail);
    }

    /** @param array<string, mixed> $feature @param array{successes:int,warnings:int,failures:int} $counts @param list<array<string,mixed>> $databaseIssues */
    private function correctTopOfHourFeature(array &$feature, array $counts, array $databaseIssues): void
    {
        $stats = (array)($feature['stats'] ?? []);
        $executions = (int)($stats['successful_executions'] ?? 0) + $counts['successes'];
        $warnings = (int)($stats['warnings'] ?? 0) + $counts['warnings'];
        $failures = (int)($stats['failures'] ?? 0) + $counts['failures'];

        $this->writeStats($feature, $executions, $warnings, $failures);
        $this->replaceClockProblems($feature, $databaseIssues);
        $this->applyOutcomeStatus($feature);

        $details = (array)($feature['details'] ?? []);
        $details[] = ['label' => __('Legal IDs queued in range'), 'value' => $counts['successes']];
        $details[] = ['label' => __('Legal-ID fallbacks in range'), 'value' => $counts['failures']];
        $feature['details'] = $details;
    }

    /** @param array<string,mixed> $feature */
    private function writeStats(array &$feature, int $executions, int $warnings, int $failures): void
    {
        $stats = (array)($feature['stats'] ?? []);
        $checksPassed = (int)($stats['checks_passed'] ?? 0);
        $successes = $checksPassed + $executions;
        $observations = $successes + $warnings + $failures;
        $stats['successful_executions'] = $executions;
        $stats['successes'] = $successes;
        $stats['warnings'] = $warnings;
        $stats['failures'] = $failures;
        $stats['observations'] = $observations;
        $stats['success_rate'] = $observations > 0
            ? round(($successes / $observations) * 100, 1)
            : null;
        $feature['stats'] = $stats;
    }

    /** @param array<string,mixed> $feature @param list<array<string,mixed>> $databaseIssues */
    private function replaceClockProblems(array &$feature, array $databaseIssues): void
    {
        $problems = array_values(array_filter(
            (array)($feature['top_problems'] ?? []),
            fn(mixed $issue): bool => is_array($issue) && !$this->isDuplicateClockDiagnosticIssue($issue),
        ));
        $problems = [...$problems, ...$databaseIssues];
        $feature['top_problems'] = array_slice($this->sortIssues($this->uniqueIssues($problems)), 0, 4);
        $feature['issues'] = max((int)($feature['issues'] ?? 0), count($feature['top_problems']));

        $drilldown = array_values(array_filter(
            (array)($feature['drilldown'] ?? []),
            static fn(mixed $row): bool => !is_array($row)
                || !in_array((string)($row['title'] ?? ''), self::DUPLICATE_CLOCK_MESSAGES, true),
        ));
        foreach (array_slice($databaseIssues, 0, 6) as $issue) {
            $drilldown[] = [
                'state' => 'critical' === ($issue['severity'] ?? null) ? 'failure' : 'warning',
                'title' => (string)($issue['title'] ?? __('Execution issue')),
                'detail' => (string)($issue['detail'] ?? ''),
                'timestamp' => (int)($issue['timestamp'] ?? 0),
                'source' => 'database_execution',
            ];
        }
        $feature['drilldown'] = array_slice($drilldown, 0, 12);
    }

    /** @param array<string,mixed> $feature */
    private function applyOutcomeStatus(array &$feature): void
    {
        if ('inactive' === ($feature['status'] ?? null)) {
            return;
        }
        $stats = (array)($feature['stats'] ?? []);
        if ((int)($stats['failures'] ?? 0) > 0) {
            $feature['status'] = 'critical';
        } elseif ((int)($stats['warnings'] ?? 0) > 0) {
            $feature['status'] = 'warning';
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param list<array{message:string,timestamp:int}> $duplicateEvents
     * @return list<array<string,int>>
     */
    private function buildFinalTimeline(
        array $snapshot,
        Station $station,
        int $start,
        int $end,
        int $bucketSeconds,
        ?string $featureFilter,
        array $duplicateEvents,
    ): array {
        if (null !== $featureFilter) {
            $timeline = $this->emptyTimeline($start, $end, $bucketSeconds);
            $feature = (array)($snapshot['features'][0] ?? []);
            foreach ((array)($feature['activity'] ?? []) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $bucket = $this->bucketFor((int)($point['timestamp'] ?? 0), $bucketSeconds);
                if (!isset($timeline[$bucket])) {
                    continue;
                }
                $timeline[$bucket]['info'] += (int)($point['success'] ?? 0);
                $timeline[$bucket]['warning'] += (int)($point['warning'] ?? 0);
                $timeline[$bucket]['critical'] += (int)($point['failure'] ?? 0);
            }
        } else {
            $timeline = $this->emptyTimeline($start, $end, $bucketSeconds);
            foreach ((array)($snapshot['timeline'] ?? []) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $bucket = $this->bucketFor((int)($point['timestamp'] ?? 0), $bucketSeconds);
                if (!isset($timeline[$bucket])) {
                    continue;
                }
                $timeline[$bucket]['info'] += (int)($point['info'] ?? 0);
                $timeline[$bucket]['warning'] += (int)($point['warning'] ?? 0);
                $timeline[$bucket]['critical'] += (int)($point['critical'] ?? 0);
            }
        }

        // The original diagnostic warning is emitted for the same persisted
        // ClockWheelEvent. Remove that copy before adding authoritative DB rows.
        if (null === $featureFilter || 'clock-wheels' === $featureFilter) {
            foreach ($duplicateEvents as $event) {
                $bucket = $this->bucketFor($event['timestamp'], $bucketSeconds);
                if (isset($timeline[$bucket])) {
                    $timeline[$bucket]['warning'] = max(0, $timeline[$bucket]['warning'] - 1);
                }
            }
        }

        $this->addDatabaseExecutions($timeline, $station, $start, $end, $bucketSeconds, $featureFilter);

        return array_values($timeline);
    }

    /** @param array<int,array<string,int>> $timeline */
    private function addDatabaseExecutions(
        array &$timeline,
        Station $station,
        int $start,
        int $end,
        int $bucketSeconds,
        ?string $featureFilter,
    ): void {
        if (null === $featureFilter || in_array($featureFilter, ['playlists', 'smart-blocks', 'remote-streams', 'playlist-groups', 'requests'], true)) {
            $this->addSongHistoryExecutions($timeline, $station, $start, $end, $bucketSeconds, $featureFilter);
        }

        if (null === $featureFilter || 'rss-podcasts' === $featureFilter) {
            $this->addPodcastExecutions($timeline, $station, $start, $end, $bucketSeconds);
        }

        if (null === $featureFilter || in_array($featureFilter, ['clock-wheels', 'top-of-hour'], true)) {
            $this->addClockWheelExecutions($timeline, $station, $start, $end, $bucketSeconds, $featureFilter);
        }

        if (null === $featureFilter || 'live-broadcasting' === $featureFilter) {
            $this->addLiveBroadcastExecutions($timeline, $station, $start, $end, $bucketSeconds);
        }
    }

    /** @param array<int,array<string,int>> $timeline */
    private function addSongHistoryExecutions(array &$timeline, Station $station, int $start, int $end, int $bucketSeconds, ?string $featureFilter): void
    {
        try {
            $dql = 'SELECT h.timestamp_start AS ts FROM App\\Entity\\SongHistory h';
            $where = ['h.station = :station', 'h.timestamp_start BETWEEN :start AND :end'];
            $params = [
                'station' => $station,
                'start' => $this->utcDate($start),
                'end' => $this->utcDate($end),
            ];

            if ('smart-blocks' === $featureFilter) {
                $dql .= ' JOIN h.playlist p';
                $where[] = 'p.is_smart_block = true';
            } elseif ('remote-streams' === $featureFilter) {
                $dql .= ' JOIN h.playlist p';
                $where[] = 'p.source = :source';
                $params['source'] = PlaylistSources::RemoteUrl->value;
            } elseif ('playlists' === $featureFilter) {
                $where[] = 'h.playlist IS NOT NULL';
            } elseif ('playlist-groups' === $featureFilter) {
                $where[] = 'h.playlist_chain IS NOT NULL';
            } elseif ('requests' === $featureFilter) {
                $where[] = 'h.request IS NOT NULL';
            }

            $query = $this->em->createQuery($dql . ' WHERE ' . implode(' AND ', $where));
            foreach ($params as $key => $value) {
                $query->setParameter($key, $value);
            }
            foreach ($query->toIterable() as $row) {
                $timestamp = $this->timestampValue(is_array($row) ? ($row['ts'] ?? null) : null);
                $this->incrementTimeline($timeline, $timestamp, 'info', $bucketSeconds);
            }
        } catch (Throwable) {
        }
    }

    /** @param array<int,array<string,int>> $timeline */
    private function addPodcastExecutions(array &$timeline, Station $station, int $start, int $end, int $bucketSeconds): void
    {
        try {
            $query = $this->em->createQuery(
                <<<'DQL'
                    SELECT e.created_at AS ts
                    FROM App\Entity\PodcastEpisode e
                    JOIN e.podcast p
                    WHERE p.storage_location = :storage
                      AND p.source = :source
                      AND e.created_at BETWEEN :start AND :end
                DQL
            )
                ->setParameter('storage', $station->podcasts_storage_location)
                ->setParameter('source', PodcastSources::Import->value)
                ->setParameter('start', $start)
                ->setParameter('end', $end);

            foreach ($query->toIterable() as $row) {
                $this->incrementTimeline(
                    $timeline,
                    $this->timestampValue(is_array($row) ? ($row['ts'] ?? null) : null),
                    'info',
                    $bucketSeconds,
                );
            }
        } catch (Throwable) {
        }
    }

    /** @param array<int,array<string,int>> $timeline */
    private function addClockWheelExecutions(array &$timeline, Station $station, int $start, int $end, int $bucketSeconds, ?string $featureFilter): void
    {
        try {
            $query = $this->em->createQuery(
                <<<'DQL'
                    SELECT e.event_timestamp AS ts,
                           e.event_kind AS kind,
                           e.anchor_type AS anchor,
                           IDENTITY(e.clock_wheel) AS wheel_id
                    FROM App\Entity\ClockWheelEvent e
                    WHERE e.station = :station AND e.event_timestamp BETWEEN :start AND :end
                DQL
            )
                ->setParameter('station', $station)
                ->setParameter('start', $this->utcDate($start))
                ->setParameter('end', $this->utcDate($end));

            foreach ($query->toIterable() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $isLegalId = 'legal_id' === (string)($row['anchor'] ?? '')
                    && null === ($row['wheel_id'] ?? null);
                if ('clock-wheels' === $featureFilter && $isLegalId) {
                    continue;
                }
                if ('top-of-hour' === $featureFilter && !$isLegalId) {
                    continue;
                }

                $kind = $row['kind'] ?? null;
                $kind = $kind instanceof BackedEnum ? $kind->value : (string)$kind;
                $severity = match ($kind) {
                    'fallback' => 'critical',
                    'deferred' => 'warning',
                    default => 'info',
                };
                $this->incrementTimeline(
                    $timeline,
                    $this->timestampValue($row['ts'] ?? null),
                    $severity,
                    $bucketSeconds,
                );
            }
        } catch (Throwable) {
        }
    }

    /** @param array<int,array<string,int>> $timeline */
    private function addLiveBroadcastExecutions(array &$timeline, Station $station, int $start, int $end, int $bucketSeconds): void
    {
        try {
            $query = $this->em->createQuery(
                <<<'DQL'
                    SELECT b.timestampStart AS ts
                    FROM App\Entity\StationStreamerBroadcast b
                    WHERE b.station = :station AND b.timestampStart BETWEEN :start AND :end
                DQL
            )
                ->setParameter('station', $station)
                ->setParameter('start', $this->utcDate($start))
                ->setParameter('end', $this->utcDate($end));

            foreach ($query->toIterable() as $row) {
                $this->incrementTimeline(
                    $timeline,
                    $this->timestampValue(is_array($row) ? ($row['ts'] ?? null) : null),
                    'info',
                    $bucketSeconds,
                );
            }
        } catch (Throwable) {
        }
    }

    /** @return array<int,array<string,int>> */
    private function emptyTimeline(int $start, int $end, int $bucketSeconds): array
    {
        $first = $this->bucketFor($start, $bucketSeconds);
        $last = $this->bucketFor($end, $bucketSeconds);
        $timeline = [];
        for ($timestamp = $first; $timestamp <= $last; $timestamp += $bucketSeconds) {
            $timeline[$timestamp] = [
                'timestamp' => $timestamp,
                'info' => 0,
                'warning' => 0,
                'critical' => 0,
            ];
        }
        return $timeline;
    }

    /** @param array<int,array<string,int>> $timeline */
    private function incrementTimeline(array &$timeline, int $timestamp, string $severity, int $bucketSeconds): void
    {
        if ($timestamp <= 0) {
            return;
        }
        $bucket = $this->bucketFor($timestamp, $bucketSeconds);
        if (isset($timeline[$bucket][$severity])) {
            ++$timeline[$bucket][$severity];
        }
    }

    private function bucketFor(int $timestamp, int $bucketSeconds): int
    {
        return intdiv($timestamp, $bucketSeconds) * $bucketSeconds;
    }

    private function timestampValue(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }
        if (is_string($value) && '' !== trim($value)) {
            try {
                return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
            } catch (Throwable) {
            }
        }
        return 0;
    }

    private function utcDate(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /** @param array<string,mixed> $issue */
    private function isDuplicateClockDiagnosticIssue(array $issue): bool
    {
        if ('diagnostics' !== ($issue['source'] ?? null)) {
            return false;
        }
        return in_array((string)($issue['title'] ?? ''), self::DUPLICATE_CLOCK_MESSAGES, true);
    }

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function uniqueIssues(array $issues): array
    {
        $seen = [];
        $result = [];
        foreach ($issues as $issue) {
            $key = implode('|', [
                (string)($issue['feature_key'] ?? ''),
                (string)($issue['title'] ?? ''),
                (string)($issue['detail'] ?? ''),
                (string)($issue['timestamp'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $issue;
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function sortIssues(array $issues): array
    {
        usort($issues, static function (array $a, array $b): int {
            $severity = ['critical' => 2, 'warning' => 1, 'success' => 0];
            $cmp = ($severity[$b['severity'] ?? ''] ?? 0) <=> ($severity[$a['severity'] ?? ''] ?? 0);
            return 0 !== $cmp
                ? $cmp
                : ((int)($b['timestamp'] ?? 0) <=> (int)($a['timestamp'] ?? 0));
        });
        return $issues;
    }

    /** @param array<string,mixed> $snapshot */
    private function recalculateSummary(array &$snapshot): void
    {
        $features = array_values(array_filter((array)($snapshot['features'] ?? []), 'is_array'));
        $services = array_values(array_filter((array)($snapshot['services'] ?? []), 'is_array'));
        $distribution = [
            'healthy' => 0,
            'monitoring' => 0,
            'warning' => 0,
            'critical' => 0,
            'inactive' => 0,
        ];
        $successes = 0;
        $warnings = 0;
        $failures = 0;
        $scores = [];
        $weights = ['healthy' => 100, 'monitoring' => 82, 'warning' => 58, 'critical' => 12];

        foreach ($features as $feature) {
            $status = (string)($feature['status'] ?? 'inactive');
            if (array_key_exists($status, $distribution)) {
                ++$distribution[$status];
            }
            if (isset($weights[$status])) {
                $scores[] = $weights[$status];
            }
            $stats = (array)($feature['stats'] ?? []);
            $successes += (int)($stats['successes'] ?? 0);
            $warnings += (int)($stats['warnings'] ?? 0);
            $failures += (int)($stats['failures'] ?? 0);
        }

        $overall = 'healthy';
        foreach ([...$features, ...$services] as $item) {
            if ('critical' === ($item['status'] ?? null)) {
                $overall = 'critical';
                break;
            }
        }
        if ('healthy' === $overall) {
            foreach ($features as $feature) {
                if ('warning' === ($feature['status'] ?? null)) {
                    $overall = 'warning';
                    break;
                }
            }
        }
        if ('healthy' === $overall) {
            foreach ($features as $feature) {
                if ('monitoring' === ($feature['status'] ?? null)) {
                    $overall = 'monitoring';
                    break;
                }
            }
        }

        $counts = (array)($snapshot['counts'] ?? []);
        foreach ($distribution as $status => $count) {
            $counts[$status] = $count;
        }
        $counts['successes'] = $successes;
        $counts['warning_signals'] = $warnings;
        $counts['failures'] = $failures;
        $counts['active_issues'] = count((array)($snapshot['recent_issues'] ?? []));

        $snapshot['counts'] = $counts;
        $snapshot['distribution'] = $distribution;
        $snapshot['health_score'] = [] === $scores ? 100 : (int)round(array_sum($scores) / count($scores));
        $snapshot['overall_status'] = $overall;
    }
}
