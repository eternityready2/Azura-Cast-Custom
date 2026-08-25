<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Radio\Schedule\ScheduleConflictChecker;
use App\Service\HolidayOverrideService;
use DateTimeImmutable;

/**
 * Resolves which subsystem owns the legal-ID boundary for a given hour.
 */
final class TopOfHourOwnershipResolver
{
    public function __construct(
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
        private readonly HolidayOverrideService $holidayOverrideService,
        private readonly ScheduleConflictChecker $conflictChecker,
    ) {
    }

    public function clockWheelHandlesLegalId(
        Station $station,
        DateTimeImmutable $when,
    ): bool {
        if (
            $this->conflictChecker->hasEmergencyScheduleActive($station, $when)
            || $this->conflictChecker->hasNonClockWheelScheduleActive($station, $when)
        ) {
            return false;
        }

        $wheel = $this->findScheduledClockWheel($station, $when)
            ?? $this->holidayOverrideService->getHolidayClockWheel($station, $when);

        if (!$wheel instanceof StationClockWheel || !$wheel->is_active) {
            return false;
        }

        foreach ($wheel->slots as $slot) {
            if (ClockWheelSlotTypes::isMandatoryTopOfHourSlot($slot->type, $slot->position_seconds)) {
                return true;
            }
        }

        return false;
    }

    private function findScheduledClockWheel(
        Station $station,
        DateTimeImmutable $when,
    ): ?StationClockWheel {
        $tz = $station->getTimezoneObject();

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            if ($schedule->clock_wheel === null) {
                continue;
            }

            if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $when)) {
                return $schedule->clock_wheel;
            }
        }

        return null;
    }
}
