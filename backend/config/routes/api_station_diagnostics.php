<?php

declare(strict_types=1);

use App\Controller;
use App\Enums\StationPermissions;
use App\Middleware;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $group): void {
    $group->group(
        '/station/{station_id}',
        function (RouteCollectorProxy $group): void {
            $group->get(
                '/features/aircheck/health',
                Controller\Api\Stations\Features\AirCheckHealthAction::class
            )->setName('api:stations:aircheck:health')
                ->add(new Middleware\Permissions(StationPermissions::View, true))
                ->add(Middleware\RequireLogin::class);

            $group->post(
                '/features/aircheck/check',
                Controller\Api\Stations\Features\AirCheckRunAction::class
            )->setName('api:stations:aircheck:check')
                ->add(new Middleware\Permissions(StationPermissions::Broadcasting, true))
                ->add(Middleware\RequireLogin::class);

            $group->get(
                '/diagnostics/download',
                Controller\Api\Stations\Diagnostics\DownloadAction::class
            )->setName('api:stations:diagnostics:download')
                ->add(new Middleware\Permissions(StationPermissions::Logs, true))
                ->add(Middleware\RequireLogin::class);
        }
    )->add(Middleware\RequireStation::class)
        ->add(Middleware\GetStation::class);
};
