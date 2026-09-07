<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Event\GetSyncTasks;
use App\Sync\Task\StageTopOfHourStationIdTask;
use Plugin\TopOfHour\RigidScheduleRuntimeConfiguration;
use Plugin\TopOfHour\TopOfHourRuntimeConfiguration;

require_once __DIR__ . '/src/RigidScheduleRuntimeConfiguration.php';
require_once __DIR__ . '/src/TopOfHourRuntimeConfiguration.php';

return static function (CallableEventDispatcherInterface $dispatcher): void {
    $dispatcher->addServiceSubscriber([
        RigidScheduleRuntimeConfiguration::class,
        TopOfHourRuntimeConfiguration::class,
    ]);

    // Staging is operationally owned by the plugin as well. Removing or disabling
    // the plugin therefore removes both the absolute wall-clock runtime lane and
    // its pre-staging task without touching unrelated AutoDJ behavior.
    $dispatcher->addListener(
        GetSyncTasks::class,
        static function (GetSyncTasks $event): void {
            $event->addTasks([
                StageTopOfHourStationIdTask::class,
            ]);
        }
    );
};
