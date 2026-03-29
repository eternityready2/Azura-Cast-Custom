<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin\Updates;

use App\Container\SettingsAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Admin\UpdateDetails;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\AzuraCastCentral;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use App\Version;

#[OA\Get(
    path: '/admin/updates',
    operationId: 'getUpdateStatus',
    summary: 'Show information about this installation and its update status.',
    tags: [OpenApi::TAG_ADMIN],
    responses: [
        new OpenApi\Response\Success(
            content: new OA\JsonContent(
                ref: UpdateDetails::class
            )
        ),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class GetUpdatesAction implements SingleActionInterface
{
    use SettingsAwareTrait;

    public function __construct(
        private readonly AzuraCastCentral $azuracastCentral
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $settings = $this->readSettings();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/eternityready2/Azura-Cast-Custom/releases/latest");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, "AzuraCast-Custom-Updater"); 
        $githubResponse = curl_exec($ch);
        curl_close($ch);

        $githubData = json_decode($githubResponse, true);
        $latestTag = $githubData['tag_name'] ?? 'v0.23.4';

        $updates = [
            'success' => true,
            'message' => 'Sincronizado con GitHub (Eternity Ready).',
            'updates' => [
                'needs_release_update' => ($latestTag !== 'v' . Version::STABLE_VERSION),
                'latest_release' => $latestTag,
                'release_url' => $githubData['html_url'] ?? 'https://github.com/eternityready2/Azura-Cast-Custom/releases',
                'needs_rolling_update' => false,
            ],
        ];
        $settings->update_results = $updates;
        $this->writeSettings($settings);

        return $response->withJson($updates);
    }
}
