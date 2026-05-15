<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiNews;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Station;
use App\Http\HttpFactory;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\AiNewsGenerator;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
        path: '/station/{station_id}/ai-news/bulletin',
        operationId: 'getStationAiNewsBulletin',
        summary: 'Stream or check availability of the latest AI news bulletin audio.',
        tags: [OpenApi::TAG_STATIONS_BROADCASTING],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Audio file stream or availability response.',
                content: [
                    new OA\MediaType(
                        mediaType: 'audio/mpeg',
                        schema: new OA\Schema(type: 'string', format: 'binary')
                    ),
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'available', type: 'boolean'),
                                new OA\Property(property: 'message', type: 'string'),
                            ]
                        )
                    ),
                ]
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
        ]
    )
]
final class BulletinGetAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly HttpFactory $httpFactory,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());
        $bulletinPath = $station->getRadioTempDir() . '/' . AiNewsGenerator::OUTPUT_FILENAME;

        if (!file_exists($bulletinPath)) {
            return $response->withStatus(404)->withJson([
                'available' => false,
                'message' => 'No bulletin file found.',
            ]);
        }

        return $this->streamBulletin($station, $response, $bulletinPath);
    }

    public static function getBulletinUrl(Station $station): string
    {
        return '/api/station/' . $station->id . '/ai-news/bulletin';
    }

    private function streamBulletin(Station $station, Response $response, string $bulletinPath): ResponseInterface
    {
        $fileSize = filesize($bulletinPath);
        $lastModified = filemtime($bulletinPath);
        $dispositionFilename = sprintf(
            '%s-ai-news-bulletin.mp3',
            preg_replace('/[^a-z0-9]+/i', '-', strtolower($station->short_name)) ?: 'station'
        );

        return $response
            ->withHeader('Content-Type', 'audio/mpeg')
            ->withHeader('Content-Length', (string) $fileSize)
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', (int) $lastModified))
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', 'inline; filename="' . $dispositionFilename . '"')
            ->withHeader('Cache-Control', 'private, max-age=60, must-revalidate')
            ->withBody($this->httpFactory->createStreamFromFile($bulletinPath, 'r'));
    }
}
