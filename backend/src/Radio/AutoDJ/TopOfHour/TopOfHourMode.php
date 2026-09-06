<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

enum TopOfHourMode: string
{
    /** A rigid :00 event owns the boundary; the ID is backtimed to end exactly at :00. */
    case HardToh = 'hard_toh';

    /** No rigid :00 event owns the boundary; the ID opens minute :59 and normal continuity follows it. */
    case SoftEtm = 'soft_etm';
}
