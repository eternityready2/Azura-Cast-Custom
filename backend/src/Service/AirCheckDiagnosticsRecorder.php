<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Station;

final readonly class AirCheckDiagnosticsRecorder
{
    public function __construct(
        private StationDiagnostics $diagnostics,
    ) {
    }

    /** @param array<string, mixed> $result */
    public function recordRecoveryResult(Station $station, array $result): void
    {
        foreach ((array)($result['restarted'] ?? []) as $service) {
            $this->diagnostics->warning(
                $station,
                'AirCheck',
                'AirCheck restarted a failed station service.',
                ['service' => $this->getStationServiceLabel((string)$service)]
            );
        }

        foreach ((array)($result['failures'] ?? []) as $failure) {
            $this->diagnostics->error(
                $station,
                'AirCheck',
                'AirCheck could not complete a station recovery action.',
                ['error' => (string)$failure]
            );
        }
    }

    /** @param array<string, mixed> $service */
    public function recordSystemServiceTransition(
        Station $station,
        array $service,
        ?bool $previousState,
    ): void {
        $running = $service['running'] ?? null;
        if (!is_bool($running)) {
            return;
        }

        $name = (string)($service['name'] ?? $service['key'] ?? 'System service');
        $key = (string)($service['key'] ?? 'unknown');

        if (!$running && false !== $previousState) {
            $this->diagnostics->warning(
                $station,
                'AirCheck',
                'A shared system dependency is not running.',
                [
                    'service' => $name,
                    'key' => $key,
                    'recovery' => 'monitor_only',
                ]
            );
            return;
        }

        if ($running && false === $previousState) {
            $this->diagnostics->info(
                $station,
                'AirCheck',
                'A shared system dependency recovered.',
                [
                    'service' => $name,
                    'key' => $key,
                ]
            );
        }
    }

    private function getStationServiceLabel(string $service): string
    {
        return match ($service) {
            'backend' => 'Liquidsoap',
            'frontend' => 'Broadcast Frontend',
            default => $service,
        };
    }
}
