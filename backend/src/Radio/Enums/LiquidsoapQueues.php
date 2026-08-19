<?php

declare(strict_types=1);

namespace App\Radio\Enums;

enum LiquidsoapQueues: string
{
    case Requests = 'requests';
    case Interrupting = 'interrupting_requests';

    /**
     * Dedicated queue for the mandatory top-of-hour legal ID.
     *
     * Kept separate from {@see self::Interrupting} because the two need
     * OPPOSITE Liquidsoap behaviour when Smart Ducking is enabled. Ducking wires
     * the interrupting queue in as a `voiceover` source: its content plays on
     * top of the music bed at reduced volume and the underlying track resumes
     * at full volume afterwards. That is exactly right for liners, sweepers and
     * promos -- and exactly wrong for a legal ID, which must REPLACE programme
     * audio for its full duration to count as a station identification, not sit
     * on top of a song that then carries on playing.
     *
     * With a separate queue the station keeps ducking for everything it is
     * meant for, while the legal ID gets a plain track_sensitive=false fallback
     * that takes over the output.
     */
    case TopOfHour = 'top_of_hour_requests';

    public static function default(): self
    {
        return self::Requests;
    }
}
