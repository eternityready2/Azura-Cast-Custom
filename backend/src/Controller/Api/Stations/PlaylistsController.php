<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Controller\Api\Traits\CanSearchResults;
use App\Controller\Api\Traits\CanSortResults;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use Carbon\CarbonImmutable;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Utilities\DateRange;
use Doctrine\ORM\AbstractQuery;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/** @extends AbstractScheduledEntityController<StationPlaylist> */
#[
    OA\Get(
        path: '/station/{station_id}/playlists',
        operationId: 'getPlaylists',
        summary: 'List all current playlists.',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: StationPlaylist::class)
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/playlists',
        operationId: 'addPlaylist',
        summary: 'Create a new playlist.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: StationPlaylist::class)
        ),
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: StationPlaylist::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/playlist/{id}',
        operationId: 'getPlaylist',
        summary: 'Retrieve details for a single playlist.',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Playlist ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: StationPlaylist::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/playlist/{id}',
        operationId: 'editPlaylist',
        summary: 'Update details of a single playlist.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: StationPlaylist::class)
        ),
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Playlist ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Delete(
        path: '/station/{station_id}/playlist/{id}',
        operationId: 'deletePlaylist',
        summary: 'Delete a single playlist.',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Playlist ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class PlaylistsController extends AbstractScheduledEntityController
{
    use CanSortResults;
    use CanSearchResults;

    protected string $entityClass = StationPlaylist::class;
    protected string $resourceRouteName = 'api:stations:playlist';

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        $qb = $this->em->createQueryBuilder()
            ->select('sp, spc')
            ->from(StationPlaylist::class, 'sp')
            ->leftJoin('sp.schedule_items', 'spc')
            ->where('sp.station = :station')
            ->setParameter('station', $station);

        // Used by the dedicated Smart Blocks page to show only Smart Block playlists
        // (and, conversely, by the main Playlists page to hide them, since they're
        // fully managed on their own page).
        $smartBlockFilter = $request->getParam('is_smart_block');
        if ('1' === $smartBlockFilter) {
            $qb->andWhere('sp.is_smart_block = true');
        } elseif ('0' === $smartBlockFilter) {
            $qb->andWhere('sp.is_smart_block = false');
        }

        $qb = $this->sortQueryBuilder(
            $request,
            $qb,
            [
                'name' => 'sp.name',
            ],
            'sp.name'
        );

        $qb = $this->searchQueryBuilder(
            $request,
            $qb,
            [
                'sp.name',
                'sp.description',
            ]
        );

        return $this->listPaginatedFromQuery($request, $response, $qb->getQuery());
    }

    /**
     * Controller used to respond to AJAX requests from the playlist "Schedule View".
     *
     * @param ServerRequest $request
     * @param Response $response
     */
    public function scheduleAction(
        ServerRequest $request,
        Response $response
    ): ResponseInterface {
        $station = $request->getStation();

        $scheduleItems = $this->em->createQuery(
            <<<'DQL'
                SELECT ssc, sp
                FROM App\Entity\StationSchedule ssc
                JOIN ssc.playlist sp
                WHERE sp.station = :station AND sp.is_jingle = 0
            DQL
        )->setParameter('station', $station)
            ->execute();

        return $this->renderEvents(
            $request,
            $response,
            $scheduleItems,
            function (
                Station $station,
                StationSchedule $scheduleItem,
                DateRange $dateRange
            ) use (
                $request
            ) {
                /** @var StationPlaylist $playlist */
                $playlist = $scheduleItem->playlist;

                return [
                    'id' => $scheduleItem->id . '_' . $dateRange->start->getTimestamp(),
                    'schedule_id' => $scheduleItem->id,
                    'playlist_id' => $playlist->id,
                    'title' => $playlist->name . ($playlist->is_enabled ? '' : ' (' . __('Disabled') . ')'),
                    'start' => $dateRange->start->toIso8601String(),
                    'end' => $dateRange->end->toIso8601String(),
                    'edit_url' => $request->getRouter()->named(
                        'api:stations:playlist',
                        ['station_id' => $station->id, 'id' => $playlist->id]
                    ),
                    // Playlist detail fields for the hover overlay panel
                    'source' => $playlist->source->value,
                    'order' => $playlist->order->value,
                    'playlist_type' => $playlist->type->value,
                    'weight' => $playlist->weight,
                    'is_enabled' => $playlist->is_enabled,
                    'play_per_songs' => $playlist->play_per_songs,
                    'play_per_minutes' => $playlist->play_per_minutes,
                    'play_per_hour_minute' => $playlist->play_per_hour_minute,
                    'avoid_duplicates' => $playlist->avoid_duplicates,
                    'is_jingle' => $playlist->is_jingle,
                    'num_songs' => $playlist->media_items->count(),
                    'total_length' => array_sum(
                        $playlist->media_items->map(
                            fn ($spm) => $spm->media?->length ?? 0
                        )->toArray()
                    ),
                    'members' => $playlist->group_members->map(
                        fn ($spg) => [
                            'id' => $spg->playlist->id,
                            'name' => $spg->playlist->name,
                            'source' => $spg->playlist->source->value,
                            'consecutive_plays' => $spg->consecutive_plays,
                            'play_full_cycle' => $spg->play_full_cycle,
                        ]
                    )->toArray(),
                    'is_member_of_group' => $playlist->group_memberships->count() > 0,
                    'group_schedule_warning' => $this->hasGroupScheduleConflict(
                        $playlist,
                        $dateRange
                    ),
                ];
            }
        );
    }

    /**
     * @return mixed[]
     */
    /**
     * Check whether a playlist's schedule window falls outside all of its parent group's
     * schedule windows. If so, the playlist would show on the calendar but never actually
     * play (since group members only play when their parent group is also scheduled/active).
     */
    private function hasGroupScheduleConflict(
        StationPlaylist $playlist,
        DateRange $memberDateRange
    ): bool {
        if ($playlist->group_memberships->count() === 0) {
            return false;
        }

        $tz = $playlist->station->getTimezoneObject();
        $memberStart = CarbonImmutable::instance($memberDateRange->start)->setTimezone($tz);
        $memberEnd = CarbonImmutable::instance($memberDateRange->end)->setTimezone($tz);

        foreach ($playlist->group_memberships as $membership) {
            $group = $membership->group;

            // No schedule on the parent group = it runs continuously, no conflict possible.
            if ($group->schedule_items->count() === 0) {
                return false;
            }

            foreach ($group->schedule_items as $groupScheduleItem) {
                $groupStart = StationSchedule::getDateTime($groupScheduleItem->start_time, $tz, $memberStart);
                $groupEnd = StationSchedule::getDateTime($groupScheduleItem->end_time, $tz, $memberStart);

                if ($groupEnd->lte($groupStart)) {
                    $groupEnd = $groupEnd->addDay();
                }

                // Check if this group schedule covers the member's event window.
                if ($memberStart->gte($groupStart) && $memberEnd->lte($groupEnd)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function viewRecord(object $record, ServerRequest $request): array
    {
        /** @var StationPlaylist $record */

        $return = $this->toArray($record);

        /** @var array{num_songs: int, total_length: string} $songTotals */
        $songTotals = $this->em->createQuery(
            <<<'DQL'
                SELECT count(sm.id) AS num_songs, sum(sm.length) AS total_length
                FROM App\Entity\StationMedia sm
                JOIN sm.playlists spm
                WHERE spm.playlist = :playlist
            DQL
        )->setParameter('playlist', $record)
            ->getSingleResult(AbstractQuery::HYDRATE_SCALAR);

        $return['short_name'] = StationPlaylist::generateShortName($return['name']);

        $return['num_songs'] = $songTotals['num_songs'];
        $return['total_length'] = round((float)$songTotals['total_length']);

        $isInternal = $request->isInternal();
        $router = $request->getRouter();

        $return['links'] = [
            'self' => $router->fromHere(
                routeName: $this->resourceRouteName,
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
            'toggle' => $router->fromHere(
                routeName: 'api:stations:playlist:toggle',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
            'clone' => $router->fromHere(
                routeName: 'api:stations:playlist:clone',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
        ];

        if (PlaylistSources::Group === $record->source) {
            $return['links']['members'] = $router->fromHere(
                routeName: 'api:stations:playlist:members',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            );
        }

        if (PlaylistSources::Songs === $record->source) {
            if (PlaylistOrders::Sequential === $record->order) {
                $return['links']['order'] = $router->fromHere(
                    routeName: 'api:stations:playlist:order',
                    routeParams: ['id' => $record->id],
                    absolute: !$isInternal
                );
            }

            if (PlaylistOrders::Random !== $record->order) {
                $return['links']['queue'] = $router->fromHere(
                    routeName: 'api:stations:playlist:queue',
                    routeParams: ['id' => $record->id],
                    absolute: !$isInternal
                );
            }

            $return['links']['import'] = $router->fromHere(
                routeName: 'api:stations:playlist:import',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            );

            $return['links']['reshuffle'] = $router->fromHere(
                routeName: 'api:stations:playlist:reshuffle',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            );

            $return['links']['applyto'] = $router->fromHere(
                routeName: 'api:stations:playlist:applyto',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            );

            $return['links']['empty'] = $router->fromHere(
                routeName: 'api:stations:playlist:empty',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            );
        }

        foreach (['pls', 'm3u'] as $format) {
            $return['links']['export'][$format] = $router->fromHere(
                routeName: 'api:stations:playlist:export',
                routeParams: ['id' => $record->id, 'format' => $format],
                absolute: !$isInternal
            );
        }

        return $return;
    }

    /**
     * @return mixed[]
     */
    protected function toArray(object $record, array $context = []): array
    {
        return parent::toArray(
            $record,
            array_merge(
                $context,
                [
                    AbstractNormalizer::IGNORED_ATTRIBUTES => ['queue'],
                ]
            )
        );
    }
}
