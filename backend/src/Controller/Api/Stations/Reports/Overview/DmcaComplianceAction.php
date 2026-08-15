<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports\Overview;

use App\Entity\Repository\StationQueueRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/reports/overview/dmca-compliance',
    operationId: 'getStationReportDmcaCompliance',
    summary: 'Get DMCA compliance rejection log and rule status.',
    tags: [OpenApi::TAG_STATIONS_REPORTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class DmcaComplianceAction extends AbstractReportAction
{
    public function __construct(
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station   = $request->getStation();
        $config    = $station->backend_config;

        // Pull recent play history for the date range window.
        $now     = new \DateTimeImmutable('now', $station->getTimezoneObject());
        $history = $this->queueRepo->getPlayedMusicHistoryByTimeRange(
            $station,
            $now,
            $config->dmca_window_minutes ?? 180
        );

        // Build play counts per song, album, artist — music only.
        // (getPlayedMusicHistoryByTimeRange already restricts to actually-played
        // music tracks, so no additional media_type filtering is needed here.)
        $songCounts   = [];
        $albumCounts  = [];
        $artistCounts = [];

        foreach ($history as $row) {
            $songId = $row['song_id'] ?? null;
            $album  = strtolower(trim($row['album'] ?? ''));
            $artist = strtolower(trim($row['artist'] ?? ''));

            if ($songId) {
                $songCounts[$songId] = ($songCounts[$songId] ?? 0) + 1;
            }
            if (!empty($album)) {
                $albumCounts[$album] = ($albumCounts[$album] ?? 0) + 1;
            }
            if (!empty($artist)) {
                $artistCounts[$artist] = ($artistCounts[$artist] ?? 0) + 1;
            }
        }

        $maxSong   = $config->dmca_max_song_plays    ?? 3;
        $maxAlbum  = $config->dmca_max_album_plays   ?? 3;
        $maxArtist = $config->dmca_max_artist_plays  ?? 4;

        // Songs approaching or exceeding limits — music only.
        $warnings = [];
        foreach ($history as $row) {
            $songId = $row['song_id'] ?? null;
            $title  = $row['title'] ?? '';
            $artist = $row['artist'] ?? '';
            $album  = $row['album'] ?? '';

            if (!$songId) {
                continue;
            }

            $plays       = $songCounts[$songId]                     ?? 0;
            $albumPlays  = $albumCounts[strtolower(trim($album))]   ?? 0;
            $artistPlays = $artistCounts[strtolower(trim($artist))] ?? 0;

            $issues = [];

            if ($plays >= $maxSong) {
                $issues[] = "Song limit reached ($plays/$maxSong plays)";
            } elseif ($plays >= $maxSong - 1) {
                $issues[] = "Song approaching limit ($plays/$maxSong plays)";
            }

            if ($albumPlays >= $maxAlbum) {
                $issues[] = "Album limit reached ($albumPlays/$maxAlbum plays)";
            } elseif ($albumPlays >= $maxAlbum - 1) {
                $issues[] = "Album approaching limit ($albumPlays/$maxAlbum plays)";
            }

            if ($artistPlays >= $maxArtist) {
                $issues[] = "Artist limit reached ($artistPlays/$maxArtist plays)";
            } elseif ($artistPlays >= $maxArtist - 1) {
                $issues[] = "Artist approaching limit ($artistPlays/$maxArtist plays)";
            }

            if (!empty($issues)) {
                $warnings[$songId] = [
                    'song_id'      => $songId,
                    'title'        => $title,
                    'artist'       => $artist,
                    'album'        => $album,
                    'song_plays'   => $plays,
                    'album_plays'  => $albumPlays,
                    'artist_plays' => $artistPlays,
                    'issues'       => $issues,
                ];
            }
        }

        return $response->withJson([
            'enabled'        => $config->dmca_compliance_enabled ?? false,
            'window_minutes' => $config->dmca_window_minutes ?? 180,
            'limits'         => [
                'max_song_plays'         => $maxSong,
                'max_consecutive_song'   => $config->dmca_max_consecutive_song   ?? 2,
                'max_album_plays'        => $maxAlbum,
                'max_artist_plays'       => $maxArtist,
                'max_consecutive_artist' => $config->dmca_max_consecutive_artist ?? 3,
            ],
            'total_plays_in_window' => count($history),
            'warnings'      => array_values($warnings),
            'warning_count' => count($warnings),
        ]);
    }
}
