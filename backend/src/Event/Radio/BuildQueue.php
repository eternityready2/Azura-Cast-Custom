<?php

declare(strict_types=1);

namespace App\Event\Radio;

use App\Entity\Station;
use App\Entity\StationQueue;
use App\Utilities\Time;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

final class BuildQueue extends Event
{
    /** @var StationQueue[] */
    private array $nextSongs = [];

    private DateTimeImmutable $expectedCueTime;

    private DateTimeImmutable $expectedPlayTime;

    public function __construct(
        private readonly Station $station,
        ?DateTimeImmutable $expectedCueTime = null,
        ?DateTimeImmutable $expectedPlayTime = null,
        private readonly ?string $lastPlayedSongId = null,
        private readonly bool $isInterrupting = false,
        private readonly bool $isPreview = false
    ) {
        $this->expectedCueTime = $expectedCueTime ?? Time::nowUtc();
        $this->expectedPlayTime = $expectedPlayTime ?? Time::nowUtc();
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    public function getExpectedCueTime(): DateTimeImmutable
    {
        return $this->expectedCueTime;
    }

    public function getExpectedPlayTime(): DateTimeImmutable
    {
        return $this->expectedPlayTime;
    }

    public function getLastPlayedSongId(): ?string
    {
        return $this->lastPlayedSongId;
    }

    public function isInterrupting(): bool
    {
        return $this->isInterrupting;
    }

    /**
     * True when this dispatch is a projection for the linear/24-hour log report
     * (or any other non-live simulation), not a real build of the live AutoDJ
     * queue that Liquidsoap will actually play. Listeners with side effects that
     * only make sense once, in real time -- generating audio, enqueuing directly
     * to the live Liquidsoap "Requests" queue, real-time cooldown/dedup state --
     * MUST no-op when this is true. Pure selection logic (playlists, clock
     * wheels, schedules, DMCA/duplicate validators) should keep running as
     * normal so the projected log still reflects real scheduling.
     */
    public function isPreview(): bool
    {
        return $this->isPreview;
    }

    /**
     * @return StationQueue[]
     */
    public function getNextSongs(): array
    {
        return $this->nextSongs;
    }

    /**
     * @param StationQueue|StationQueue[]|null $nextSongs
     *        Pass null to clear a previously selected pick (e.g. DMCA rejection).
     * @return bool True when the selection was updated (set or cleared).
     */
    public function setNextSongs(StationQueue|array|null $nextSongs): bool
    {
        // Clear selection so validators (DMCA) can reject a pick and force a retry.
        // Do not stopPropagation here — later listeners still need to run.
        if (null === $nextSongs) {
            $this->nextSongs = [];
            return true;
        }

        if (!is_array($nextSongs)) {
            if ($this->lastPlayedSongId === $nextSongs->song_id) {
                return false;
            }

            $this->nextSongs = [$nextSongs];
        } else {
            $this->nextSongs = $nextSongs;
        }

        // Intentionally do NOT stopPropagation: lower-priority validators such as
        // DmcaComplianceListener must still run after a successful selector pick.
        // Selectors themselves early-return when getNextSongs() is already non-empty.
        return true;
    }

    public function __toString(): string
    {
        return !empty($this->nextSongs)
            ? implode(', ', array_map('strval', $this->nextSongs))
            : 'No Song';
    }
}
