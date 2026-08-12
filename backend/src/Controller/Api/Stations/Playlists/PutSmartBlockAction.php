<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\CustomField;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\StationPlaylistSmartBlockCriteria;
use App\Exception;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\SmartBlock\SmartBlockSyncer;
use App\Utilities\Types;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Put(
    path: '/station/{station_id}/playlist/{id}/smart-block',
    operationId: 'putStationPlaylistSmartBlock',
    summary: 'Set the Smart Block criteria for the specified playlist and immediately re-sync its membership.',
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
)]
final readonly class PutSmartBlockAction implements SingleActionInterface
{
    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private EntityManagerInterface $em,
        private SmartBlockSyncer $syncer,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        /** @var string $id */
        $id = $params['id'];

        $station = $request->getStation();
        $record = $this->playlistRepo->requireForStation($id, $station);

        if (PlaylistSources::Songs !== $record->source) {
            throw new Exception(__('Smart Blocks are only available for Song-Based playlists.'));
        }

        // Basic playlist fields, editable directly from the Smart Blocks page so it can
        // stand fully on its own without bouncing back to the Playlists page.
        $name = Types::stringOrNull($request->getParam('name'));
        if (null !== $name && '' !== trim($name)) {
            $record->name = $name;
        }
        $record->is_enabled = Types::bool($request->getParam('is_enabled', $record->is_enabled));
        $record->weight = Types::int($request->getParam('weight'), $record->weight);

        $record->is_smart_block = Types::bool($request->getParam('is_smart_block', false));

        $record->smart_block_type = SmartBlockType::tryFrom(
            Types::stringOrNull($request->getParam('smart_block_type')) ?? ''
        ) ?? SmartBlockType::Dynamic;

        $record->smart_block_match_type = SmartBlockMatchType::tryFrom(
            Types::stringOrNull($request->getParam('smart_block_match_type')) ?? ''
        ) ?? SmartBlockMatchType::All;

        $record->smart_block_limit = Types::intOrNull($request->getParam('smart_block_limit'));

        $record->smart_block_limit_type = SmartBlockLimitType::tryFrom(
            Types::stringOrNull($request->getParam('smart_block_limit_type')) ?? ''
        ) ?? SmartBlockLimitType::Tracks;

        /** @var array<array{
         *     field?: mixed,
         *     custom_field_id?: mixed,
         *     comparison?: mixed,
         *     value?: mixed,
         *     value2?: mixed
         * }> $criteria
         */
        $criteria = Types::array($request->getParam('criteria'));

        // Full recreate on every save. This is a direct bulk DELETE against the
        // database rather than loading-then-removing each entity -- that approach
        // depended on the in-memory collection accurately reflecting the current DB
        // state, and if it was stale (e.g. lazy-loaded before this request touched
        // anything else), "delete the old rows" would silently delete nothing, and
        // the new rows would pile up on top of the old ones on every single save.
        // A bulk DELETE can't be fooled by stale in-memory state.
        $this->em->createQuery(
            'DELETE FROM ' . StationPlaylistSmartBlockCriteria::class . ' c WHERE c.playlist = :playlist'
        )
            ->setParameter('playlist', $record)
            ->execute();

        // The bulk DELETE above bypasses the ORM's normal object lifecycle, so the
        // in-memory collection doesn't know those rows are gone -- clear it explicitly
        // to avoid stale entities lingering in this request.
        $record->smart_block_criteria->clear();

        $weight = 0;
        $seenFingerprints = [];

        foreach ($criteria as $criterion) {
            $field = SmartBlockCriteriaField::tryFrom(
                Types::stringOrNull($criterion['field'] ?? null) ?? ''
            ) ?? SmartBlockCriteriaField::default();

            $comparison = SmartBlockCriteriaComparison::tryFrom(
                Types::stringOrNull($criterion['comparison'] ?? null) ?? ''
            ) ?? SmartBlockCriteriaComparison::default();

            $value = Types::stringOrNull($criterion['value'] ?? null);
            $value2 = Types::stringOrNull($criterion['value2'] ?? null);
            $customFieldId = Types::intOrNull($criterion['custom_field_id'] ?? null);

            // Hard safety net: never persist exact-duplicate rows, no matter how a
            // duplicate made it into the incoming payload.
            $fingerprint = implode('|', [
                $field->value,
                $comparison->value,
                $value ?? '',
                $value2 ?? '',
                $customFieldId ?? '',
            ]);
            if (isset($seenFingerprints[$fingerprint])) {
                continue;
            }
            $seenFingerprints[$fingerprint] = true;

            $row = new StationPlaylistSmartBlockCriteria($record);
            $row->field = $field;
            $row->comparison = $comparison;
            $row->value = $value;
            $row->value2 = $value2;
            $row->weight = $weight;

            if (SmartBlockCriteriaField::CustomField === $field && null !== $customFieldId) {
                $customField = $this->em->find(CustomField::class, $customFieldId);
                if ($customField instanceof CustomField) {
                    $row->custom_field = $customField;
                }
            }

            $this->em->persist($row);
            $weight++;
        }

        $this->em->persist($record);
        $this->em->flush();
        $this->em->refresh($record);

        $syncResult = $record->is_smart_block
            ? $this->syncer->sync($record)
            : ['added' => 0, 'removed' => 0, 'total' => 0];

        return $response->withJson(
            [
                'success' => true,
                'added' => $syncResult['added'],
                'removed' => $syncResult['removed'],
                'total_members' => $syncResult['total'],
            ]
        );
    }
}
