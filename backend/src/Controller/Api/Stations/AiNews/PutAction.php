<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiNews;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[
    OA\Put(
        path: '/station/{station_id}/ai-news',
        operationId: 'putStationAiNewsSettings',
        summary: 'Save AI news bulletin settings.',
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
final class PutAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    /** @var array<int, string> */
    private const array VALID_FIELDS = [
        'ai_news_enabled',
        'ai_news_intro',
        'ai_news_reporter_name',
        'ai_news_source_urls',
        'ai_news_story_count',
        'ai_news_active_hours',
        'ai_news_active_days',
        'ai_news_top_of_hour',
        'ai_news_bottom_of_hour',
        'ai_news_voice_model_path',
        'ai_news_outro',
    ];

    /** @var array<int, string> */
    private const array RESTART_REQUIRED_FIELDS = [
        'ai_news_enabled',
        'ai_news_active_hours',
        'ai_news_active_days',
        'ai_news_top_of_hour',
        'ai_news_bottom_of_hour',
    ];

    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $body = (array) $request->getParsedBody();

        $station = $this->em->refetch($request->getStation());
        $backendConfig = $station->backend_config;
        $originalBackendConfig = clone $backendConfig;

        foreach (self::VALID_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $backendConfig->$field = $body[$field];
            }
        }

        $errors = $this->validator->validate(
            $backendConfig->ai_news_active_hours,
            [
                new Sequentially([
                    new Regex(
                        pattern: '/^(|\d{2}:\d{2}-\d{2}:\d{2})$/',
                        message: __('Active hours must be empty or use the internal HH:MM-HH:MM format.')
                    ),
                    new Regex(
                        pattern: '/^$|^(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d$/',
                        message: __('Active hours must use valid times in HH:MM-HH:MM format.')
                    ),
                ]),
            ]
        );
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        if (!$backendConfig->ai_news_top_of_hour && !$backendConfig->ai_news_bottom_of_hour) {
            $backendConfig->ai_news_top_of_hour = true;
        }

        $requiresRestart = $this->requiresRestart($originalBackendConfig, $backendConfig);

        $station->backend_config = $backendConfig;
        if (!$requiresRestart) {
            $station->needs_restart = false;
        }

        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }

    private function requiresRestart(object $originalBackendConfig, object $updatedBackendConfig): bool
    {
        foreach (self::RESTART_REQUIRED_FIELDS as $field) {
            if ($originalBackendConfig->$field !== $updatedBackendConfig->$field) {
                return true;
            }
        }

        return false;
    }
}
