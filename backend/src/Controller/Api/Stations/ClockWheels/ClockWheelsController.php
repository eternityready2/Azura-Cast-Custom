<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\Api\Stations\AbstractScheduledEntityController;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMediaCategory;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\Scheduler;
use App\Utilities\DateRange;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Clock Wheel CRUD + atomic slot management.
 *
 * Slot writes use a full-replace strategy (PUT /slots) rather than individual
 * INSERT/UPDATE/DELETE calls. This is intentional: a radio clock must always
 * represent a coherent, validated hour.  Partial mutations could leave the wheel
 * in an intermediate state that Liquidsoap would then turn into bad radio.
 *
 * @extends AbstractScheduledEntityController<StationClockWheel>
 */
#[
    OA\Get(
        path: '/station/{station_id}/clock-wheels',
        operationId: 'getClockWheels',
        summary: 'List all clock wheels for a station.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: StationClockWheel::class)
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/clock-wheels',
        operationId: 'addClockWheel',
        summary: 'Create a new clock wheel.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: StationClockWheel::class)
        ),
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: StationClockWheel::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/clock-wheel/{id}',
        operationId: 'getClockWheel',
        summary: 'Retrieve a single clock wheel with its ordered slot list.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Clock Wheel ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: StationClockWheel::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/clock-wheel/{id}',
        operationId: 'editClockWheel',
        summary: 'Update clock wheel metadata (name, color, active flag).',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: StationClockWheel::class)
        ),
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Clock Wheel ID',
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
        path: '/station/{station_id}/clock-wheel/{id}',
        operationId: 'deleteClockWheel',
        summary: 'Delete a clock wheel and all of its slots.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Clock Wheel ID',
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
    OA\Get(
        path: '/station/{station_id}/clock-wheel/{id}/slots',
        operationId: 'getClockWheelSlots',
        summary: 'Get the ordered slot list for a clock wheel.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Clock Wheel ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: StationClockWheelSlot::class)
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/clock-wheel/{id}/slots',
        operationId: 'putClockWheelSlots',
        summary: 'Atomically replace all slots for a clock wheel.',
        description: 'Replaces the entire slot list in one transaction. '
            . 'Partial updates are intentionally not supported: a radio clock '
            . 'must always represent a complete, validated hour. '
            . 'Accepts either a bare JSON array of slot objects or an object '
            . 'with a "slots" key containing the array.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Clock Wheel ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(ref: StationClockWheelSlot::class)
            )
        ),
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: StationClockWheelSlot::class)
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class ClockWheelsController extends AbstractScheduledEntityController
{
    protected string $entityClass = StationClockWheel::class;
    protected string $resourceRouteName = 'api:stations:clock-wheel';

    public function __construct(
        StationScheduleRepository $scheduleRepo,
        Scheduler $scheduler,
        Serializer $serializer,
        ValidatorInterface $validator,
    ) {
        parent::__construct($scheduleRepo, $scheduler, $serializer, $validator);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        $query = $this->em->createQueryBuilder()
            ->select('scw')
            ->from(StationClockWheel::class, 'scw')
            ->where('scw.station = :station')
            ->setParameter('station', $station)
            ->orderBy('scw.name', 'ASC')
            ->getQuery();

        return $this->listPaginatedFromQuery($request, $response, $query);
    }

    // ------------------------------------------------------------------
    // editRecord — intercepts slots before the serializer touches the entity
    // ------------------------------------------------------------------

    /**
     * Overrides the base editRecord so that the `slots` array submitted by
     * the frontend is handled in-process (not passed to the Symfony serializer,
     * which cannot write to the private(set) collection on StationClockWheel).
     *
     * The slot replacement and the entity update are committed in a single
     * flush call so the operation is atomic from the DB's perspective.
     *
     * @param array<mixed>|null    $data
     * @param StationClockWheel|null $record
     * @param array<string, mixed> $context
     *
     * @return StationClockWheel
     */
    protected function editRecord(?array $data, ?object $record = null, array $context = []): object
    {
        if (null === $data) {
            throw new InvalidArgumentException('Could not parse input data.');
        }

        // Pull slots out before deserialization. The StationClockWheel entity
        // declares `slots` as private(set) — the Symfony serializer cannot write
        // to it, so passing it through would either silently fail or throw.
        $slotsData = isset($data['slots']) && is_array($data['slots'])
            ? $data['slots']
            : null;
        unset($data['slots']);

        $scheduleItems = $data['schedule_items'] ?? null;
        unset($data['schedule_items']);

        /** @var StationClockWheel $wheel */
        $wheel = $this->fromArray($data, $record, $context);

        $errors = $this->validator->validate($wheel);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->persist($wheel);

        // Apply slot replacement now (before flush) so the entire operation
        // — wheel metadata + slot list — commits in one transaction.
        if ($slotsData !== null) {
            $this->replaceSlots($wheel, $slotsData);
        }

        if (null !== $scheduleItems) {
            $this->scheduleRepo->setScheduleItems($wheel, $scheduleItems);
        }

        $this->em->flush();

        // Refresh slot entities so the read-only category_id column reflects
        // the persisted FK value (Doctrine doesn't back-fill it in memory).
        foreach ($wheel->slots as $slot) {
            $this->em->refresh($slot);
        }

        return $wheel;
    }

    // ------------------------------------------------------------------
    // Schedule feed (FullCalendar-compatible)
    // ------------------------------------------------------------------

    public function scheduleAction(
        ServerRequest $request,
        Response $response
    ): ResponseInterface {
        $station = $request->getStation();

        $scheduleItems = $this->em->createQuery(
            <<<'DQL'
                SELECT ssc, scw
                FROM App\Entity\StationSchedule ssc
                JOIN ssc.clock_wheel scw
                WHERE scw.station = :station AND scw.is_active = 1
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
            ) use ($request) {
                /** @var StationClockWheel $wheel */
                $wheel = $scheduleItem->clock_wheel;

                return [
                    'id'              => $scheduleItem->id . '_' . $dateRange->start->getTimestamp(),
                    'title'           => $wheel->name,
                    'backgroundColor' => $wheel->color,
                    'start'           => $dateRange->start->toIso8601String(),
                    'end'             => $dateRange->end->toIso8601String(),
                    'edit_url'        => $request->getRouter()->named(
                        'api:stations:clock-wheel',
                        ['station_id' => $station->id, 'id' => $wheel->id]
                    ),
                ];
            }
        );
    }

    // ------------------------------------------------------------------
    // Single record view — embeds slot list to avoid a second request
    // ------------------------------------------------------------------

    protected function viewRecord(object $record, ServerRequest $request): mixed
    {
        assert($record instanceof StationClockWheel);

        $return = $this->toArray($record);

        // Embed ordered slots so the clock editor page has everything it needs
        // in one round-trip. Slots are already ordered ASC by slot_order via the
        // ORM OrderBy annotation on the collection.
        $slotsOut = [];
        foreach ($record->slots as $slot) {
            $slotsOut[] = $this->toArray($slot);
        }
        $return['slots'] = $slotsOut;

        $router = $request->getRouter();
        $isInternal = $request->isInternal();

        $return['links'] = [
            'self' => $router->fromHere(
                routeName: $this->resourceRouteName,
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
            'slots' => $router->fromHere(
                routeName: 'api:stations:clock-wheel:slots',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
        ];

        return $return;
    }

    // ------------------------------------------------------------------
    // Slot read endpoint
    // ------------------------------------------------------------------

    /**
     * GET /station/{station_id}/clock-wheel/{id}/slots
     *
     * Returns the ordered slot list without re-fetching wheel metadata.
     * Useful when the editor polls after a save to confirm the persisted state.
     */
    public function getSlotsAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);

        if (null === $record) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        $slots = [];
        foreach ($record->slots as $slot) {
            $slots[] = $this->toArray($slot);
        }

        return $response->withJson($slots);
    }

    // ------------------------------------------------------------------
    // Atomic slot write endpoint
    // ------------------------------------------------------------------

    /**
     * PUT /station/{station_id}/clock-wheel/{id}/slots
     *
     * Replaces the complete slot list in a single database transaction.
     *
     * Accepted payload shapes:
     *   - Bare JSON array:    [{"type":"music",...}, ...]
     *   - Wrapped object:     {"slots": [{"type":"music",...}, ...]}
     */
    public function putSlotsAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);

        if (null === $record) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        $body = (array)$request->getParsedBody();

        // Support both {"slots":[...]} and a bare array at the root.
        $slotsData = array_key_exists('slots', $body)
            ? (array)$body['slots']
            : $body;

        $this->replaceSlots($record, $slotsData);

        $errors = $this->validator->validate($record);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->flush();

        $savedSlots = [];
        foreach ($record->slots as $slot) {
            $this->em->refresh($slot);
            $savedSlots[] = $this->toArray($slot);
        }

        return $response->withJson($savedSlots);
    }

    // ------------------------------------------------------------------
    // Shared slot replacement logic
    // ------------------------------------------------------------------

    /**
     * Clears the wheel's slot collection and rebuilds it from $slotsData.
     *
     * Must be called before em->flush() so the entire wheel + slots change
     * lands in one transaction. Slot entities are persisted inside this method
     * so Doctrine tracks them; the caller is responsible for calling flush().
     *
     * Security: playlist pins are validated against the wheel's own station
     * so a crafted payload cannot reference playlists from other stations.
     *
     * @param array<mixed> $slotsData
     */
    private function replaceSlots(StationClockWheel $wheel, array $slotsData): void
    {
        $wheel->slots->clear();

        $normalized = [];
        foreach ($slotsData as $datum) {
            if (!is_array($datum)) {
                continue;
            }
            $normalized[] = $datum;
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                $posA = isset($a['position_seconds']) && is_numeric($a['position_seconds'])
                    ? (int)$a['position_seconds']
                    : 0;
                $posB = isset($b['position_seconds']) && is_numeric($b['position_seconds'])
                    ? (int)$b['position_seconds']
                    : 0;

                return $posA <=> $posB;
            }
        );

        $order = 0;
        foreach ($normalized as $datum) {
            $slot = new StationClockWheelSlot($wheel);
            $slot->slot_order = $order++;

            $posRaw = $datum['position_seconds'] ?? null;
            $slot->position_seconds = (is_numeric($posRaw) && (int)$posRaw >= 0)
                ? min(3599, (int)$posRaw)
                : 0;

            // Resolve category_id first — a category slot has no type.
            $categoryId = array_key_exists('category_id', $datum) && is_numeric($datum['category_id'])
                ? (int)$datum['category_id']
                : null;

            if ($categoryId !== null) {
                $category = $this->em->find(StationMediaCategory::class, $categoryId);
                if ($category !== null && $category->station->id === $wheel->station->id) {
                    $slot->category = $category;
                    $slot->type = null;
                } else {
                    // Fallback to music if category is invalid or from another station.
                    $slot->type = ClockWheelSlotTypes::Music;
                }
            } else {
                // Use array_key_exists so null values don't fall back to 'music'.
                $typeRaw = (array_key_exists('type', $datum) && $datum['type'] !== null)
                    ? (string)$datum['type']
                    : 'music';
                $slot->type = ClockWheelSlotTypes::tryFrom($typeRaw) ?? ClockWheelSlotTypes::Music;
            }

            $algoRaw = isset($datum['algorithm']) ? (string)$datum['algorithm'] : 'random';
            $slot->algorithm = ClockWheelSlotAlgorithms::tryFrom($algoRaw) ?? ClockWheelSlotAlgorithms::Random;

            // Optional playlist pin — must belong to the same station.
            $playlistId = isset($datum['playlist_id']) && is_numeric($datum['playlist_id'])
                ? (int)$datum['playlist_id']
                : null;

            if ($playlistId !== null && $playlistId > 0) {
                $playlist = $this->em->find(StationPlaylist::class, $playlistId);

                if ($playlist !== null && $playlist->station->id === $wheel->station->id) {
                    $slot->playlist = $playlist;
                }
                // Playlist not found or from another station → slot becomes
                // type-based (null pin). Content still airs from the type pool.
            }

            // Duration cap in seconds. NULL means "play one full track".
            $durRaw = $datum['duration_seconds'] ?? null;
            $slot->duration_seconds = (is_numeric($durRaw) && (int)$durRaw > 0)
                ? (int)$durRaw
                : null;

            $wheel->addSlot($slot);
            $this->em->persist($slot);
        }
    }
}
