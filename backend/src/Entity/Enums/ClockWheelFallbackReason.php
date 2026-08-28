<?php

declare(strict_types=1);

namespace App\Entity\Enums;

/**
 * Why clock wheel playback did not queue a track (or deferred this tick).
 */
enum ClockWheelFallbackReason: string
{
    case ScheduleConflict = 'schedule_conflict';
    case EmergencyOverride = 'emergency_override';
    case WheelInactive = 'wheel_inactive';
    case NoSlots = 'no_slots';
    case DeferredInsufficientWindow = 'deferred_insufficient_window';
    case NoSlotType = 'no_slot_type';
    case NoMediaCandidates = 'no_media_candidates';
    case NoMediaFitsWindow = 'no_media_fits_window';
    case DuplicatePreventionEmpty = 'duplicate_prevention_empty';
    case MediaNotFound = 'media_not_found';
    case AutodjFallback = 'autodj_fallback';
    /** No legal_id media available; a station promo was substituted. */
    case LegalIdMissingUsedPromo = 'legal_id_missing_used_promo';
    /** Hard anchor slot could not play at its scheduled position. */
    case HardAnchorMissed = 'hard_anchor_missed';
}
