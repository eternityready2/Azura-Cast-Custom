<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Station;
use App\Radio\AbstractLocalAdapter;
use App\Radio\Adapters;
use Throwable;

final readonly class AirCheckHealthMonitor
{
    public function __construct(
        private Adapters $adapters,
        private ServiceControl $serviceControl,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSnapshot(Station $station): array
    {
        $stationServices = [
            $this->getStationService(
                $station,
                'backend',
                $this->adapters->getBackendAdapter($station),
                'AutoDJ Backend'
            ),
            $this->getStationService(
                $station,
                'frontend',
                $this->adapters->getFrontendAdapter($station),
                'Broadcast Frontend'
            ),
        ];

        $systemServices = array_map(
            fn($service): array => [
                'key' => $service->name,
                'name' => $this->getSystemServiceLabel($service->name),
                'description' => $service->description,
                'running' => $service->running,
                'configured' => true,
                'scope' => 'system',
                'recovery' => 'monitor_only',
            ],
            $this->serviceControl->getServices()
        );

        $monitoredServices = [
            ...array_filter(
                $stationServices,
                static fn(array $service): bool => $service['configured']
            ),
            ...$systemServices,
        ];

        $total = count($monitoredServices);
        $running = count(array_filter(
            $monitoredServices,
            static fn(array $service): bool => true === $service['running']
        ));

        return [
            'healthy' => $total === $running,
            'running' => $running,
            'total' => $total,
            'station_services' => $stationServices,
            'system_services' => $systemServices,
            'timestamp' => time(),
        ];
    }

    /** @return array<string, mixed> */
    private function getStationService(
        Station $station,
        string $key,
        ?AbstractLocalAdapter $adapter,
        string $fallbackName,
    ): array {
        if (null === $adapter) {
            return [
                'key' => $key,
                'name' => $fallbackName,
                'description' => __('Not configured for this station'),
                'running' => null,
                'configured' => false,
                'scope' => 'station',
                'recovery' => 'automatic',
            ];
        }

        try {
            $running = $adapter->isRunning($station);
            $error = null;
        } catch (Throwable $e) {
            $running = false;
            $error = $e->getMessage();
        }

        $classParts = explode('\\', $adapter::class);
        $name = (string)end($classParts);

        return [
            'key' => $key,
            'name' => '' !== $name ? $name : $fallbackName,
            'description' => 'backend' === $key
                ? __('Station AutoDJ and playout engine')
                : __('Station broadcast frontend'),
            'running' => $running,
            'configured' => true,
            'scope' => 'station',
            'recovery' => 'automatic',
            'error' => $error,
        ];
    }

    private function getSystemServiceLabel(string $service): string
    {
        return match ($service) {
            'cron' => __('Scheduler / Cron'),
            'mariadb' => __('MariaDB'),
            'nginx' => __('Nginx'),
            'php-fpm' => __('PHP-FPM'),
            'php-nowplaying' => __('Now Playing Worker'),
            'php-worker' => __('Queue Worker'),
            'redis' => __('Redis'),
            'sftpgo' => __('SFTP'),
            'centrifugo' => __('Live Updates'),
            'vite' => __('Vite'),
            default => $service,
        };
    }
}
