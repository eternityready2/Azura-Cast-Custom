<?php

declare(strict_types=1);

use App\Controller;
use App\Enums\StationFeatures;
use App\Enums\StationPermissions;
use App\Middleware;
use Slim\Routing\RouteCollectorProxy;

/**
 * Supplemental playlist-configuration routes kept separate from api_station.php
 * so the upstream import/export feature can be integrated without replacing the
 * fork's heavily customized station route table.
 */
return static function (RouteCollectorProxy $group): void {
    $group->group(
        '/station/{station_id}',
        function (RouteCollectorProxy $group): void {
            $group->group(
                '',
                function (RouteCollectorProxy $group): void {
                    $group->get(
                        '/playlists/export-config',
                        Controller\Api\Stations\Playlists\ExportConfigAction::class
                    )->setName('api:stations:playlists:export-config');

                    $group->post(
                        '/playlists/import-config',
                        Controller\Api\Stations\Playlists\ImportConfigAction::class
                    )->setName('api:stations:playlists:import-config');

                    $group->get(
                        '/playlist/{id}/export-config',
                        Controller\Api\Stations\Playlists\ExportConfigAction::class
                    )->setName('api:stations:playlist:export-config');
                }
            )->add(new Middleware\StationSupportsFeature(StationFeatures::Media))
                ->add(new Middleware\Permissions(StationPermissions::Media, true))
                ->add(Middleware\RequireLogin::class);
        }
    )->add(Middleware\RequireStation::class)
        ->add(Middleware\GetStation::class);
};
