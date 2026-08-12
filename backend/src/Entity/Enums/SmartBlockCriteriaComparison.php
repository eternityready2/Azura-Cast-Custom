<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * The comparison operator applied to a single Smart Block criterion's value(s).
 */
#[OA\Schema(type: 'string')]
enum SmartBlockCriteriaComparison: string
{
    case Is = 'is';
    case IsNot = 'is_not';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case Between = 'between';

    public static function default(): self
    {
        return self::Is;
    }

    /**
     * Whether this comparison needs a second value (value2) -- currently only "Between".
     */
    public function needsSecondValue(): bool
    {
        return self::Between === $this;
    }

    /**
     * The comparisons valid for free-text fields (genre, artist, album, title, and
     * text-valued custom fields).
     *
     * @return list<self>
     */
    public static function textComparisons(): array
    {
        return [self::Is, self::IsNot, self::Contains, self::NotContains];
    }

    /**
     * The comparisons valid for numeric fields (duration, and numeric-valued custom
     * fields like BPM).
     *
     * @return list<self>
     */
    public static function numericComparisons(): array
    {
        return [self::Is, self::IsNot, self::GreaterThan, self::LessThan, self::Between];
    }
}
