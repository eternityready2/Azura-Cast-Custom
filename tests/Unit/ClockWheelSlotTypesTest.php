<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\ClockWheelSlotTypes;
use Codeception\Test\Unit;

class ClockWheelSlotTypesTest extends Unit
{
    public function testLabels(): void
    {
        self::assertSame('Music', ClockWheelSlotTypes::Music->label());
        self::assertSame('Talk',  ClockWheelSlotTypes::Talk->label());
        self::assertSame('ID',    ClockWheelSlotTypes::Id->label());
        self::assertSame('Promo', ClockWheelSlotTypes::Promo->label());
        self::assertSame('Ad',    ClockWheelSlotTypes::Ad->label());
    }

    public function testSuggestedDurationsForFixedDurationTypes(): void
    {
        self::assertSame(['min' => 5,  'max' => 30], ClockWheelSlotTypes::Id->suggestedDurationSeconds());
        self::assertSame(['min' => 30, 'max' => 60], ClockWheelSlotTypes::Promo->suggestedDurationSeconds());
        self::assertSame(['min' => 30, 'max' => 60], ClockWheelSlotTypes::Ad->suggestedDurationSeconds());
    }

    public function testNoSuggestedDurationForOpenEndedTypes(): void
    {
        self::assertNull(ClockWheelSlotTypes::Music->suggestedDurationSeconds());
        self::assertNull(ClockWheelSlotTypes::Talk->suggestedDurationSeconds());
    }

    public function testHasFixedDurationFlag(): void
    {
        self::assertTrue(ClockWheelSlotTypes::Id->hasFixedDuration());
        self::assertTrue(ClockWheelSlotTypes::Promo->hasFixedDuration());
        self::assertTrue(ClockWheelSlotTypes::Ad->hasFixedDuration());

        self::assertFalse(ClockWheelSlotTypes::Music->hasFixedDuration());
        self::assertFalse(ClockWheelSlotTypes::Talk->hasFixedDuration());
    }

    public function testFixedDurationRangeIsAlwaysValid(): void
    {
        foreach (ClockWheelSlotTypes::cases() as $type) {
            $range = $type->suggestedDurationSeconds();
            if ($range === null) {
                continue;
            }

            self::assertGreaterThanOrEqual(0, $range['min']);
            self::assertGreaterThan($range['min'], $range['max']);
            self::assertLessThanOrEqual(3600, $range['max']);
        }
    }
}
