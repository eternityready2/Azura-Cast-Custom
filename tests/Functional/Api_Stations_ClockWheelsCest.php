<?php

declare(strict_types=1);

namespace Functional;

use FunctionalTester;

final class Api_Stations_ClockWheelsCest extends CestAbstract
{
    /**
     * @return array<string, mixed>
     */
    private function defaultScheduleItem(): array
    {
        return [
            'start_time' => 900,
            'end_time' => 1700,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'days' => [1, 2, 3, 4, 5],
            'loop_once' => false,
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_end_type' => 'never',
        ];
    }

    /**
     * @before setupComplete
     * @before login
     */
    public function manageClockWheels(FunctionalTester $I): void
    {
        $I->wantTo('Manage station clock wheels via API.');

        $station = $this->getTestStation();

        $this->testCrudApi(
            $I,
            '/api/station/' . $station->id . '/clock-wheels',
            [
                'name' => 'Morning Drive Wheel',
                'color' => '#e87722',
                'is_active' => false,
            ],
            [
                'name' => 'Updated Morning Drive',
                'is_active' => false,
            ]
        );
    }

    /**
     * @before setupComplete
     * @before login
     */
    public function activeClockWheelMayExistWithoutSchedule(FunctionalTester $I): void
    {
        $I->wantTo('Allow an active clock wheel with no schedule items (scheduling is done on the station calendar).');

        $station = $this->getTestStation();
        $baseUrl = '/api/station/' . $station->id . '/clock-wheels';

        $I->sendPOST($baseUrl, [
            'name' => 'Unscheduled Active Wheel',
            'color' => '#112233',
            'is_active' => true,
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'name' => 'Unscheduled Active Wheel',
            'is_active' => true,
        ]);
    }

    /**
     * @before setupComplete
     * @before login
     */
    public function clockWheelScheduleOverlapIsRejected(FunctionalTester $I): void
    {
        $I->wantTo('Reject overlapping clock wheel schedule windows on the same station.');

        $station = $this->getTestStation();
        $baseUrl = '/api/station/' . $station->id . '/clock-wheels';
        $schedule = $this->defaultScheduleItem();

        $I->sendPOST($baseUrl, [
            'name' => 'Wheel A',
            'color' => '#e87722',
            'is_active' => true,
            'schedule_items' => [$schedule],
        ]);
        $I->seeResponseCodeIs(200);

        $I->sendPOST($baseUrl, [
            'name' => 'Wheel B',
            'color' => '#228877',
            'is_active' => true,
            'schedule_items' => [
                array_merge($schedule, [
                    'start_time' => 1000,
                    'end_time' => 1800,
                ]),
            ],
        ]);

        $I->seeResponseCodeIs(400);
        $I->seeResponseContains('conflict');
    }

    /**
     * @before setupComplete
     * @before login
     */
    public function clockWheelSlotsAndScheduleFeed(FunctionalTester $I): void
    {
        $I->wantTo('Save timed slots and expose clock wheel events on the schedule feed.');

        $station = $this->getTestStation();
        $baseUrl = '/api/station/' . $station->id . '/clock-wheels';

        $I->sendPOST($baseUrl, [
            'name' => 'Feed Test Wheel',
            'color' => '#abcdef',
            'is_active' => true,
            'schedule_items' => [$this->defaultScheduleItem()],
            'slots' => [
                [
                    'type' => 'music',
                    'algorithm' => 'random',
                    'position_seconds' => 0,
                    'duration_seconds' => null,
                ],
                [
                    'type' => 'id',
                    'algorithm' => 'random',
                    'position_seconds' => 1200,
                    'duration_seconds' => 30,
                ],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $selfLink = $I->grabDataFromResponseByJsonPath('links.self')[0];
        $wheelId = $I->grabDataFromResponseByJsonPath('id')[0];

        $I->sendGET($selfLink);
        $I->seeResponseContainsJson([
            'slots' => [
                ['position_seconds' => 0],
                ['position_seconds' => 1200],
            ],
        ]);

        $I->sendPUT($selfLink . '/slots', [
            'slots' => [
                [
                    'type' => 'promo',
                    'category_id' => null,
                    'algorithm' => 'oldest_track',
                    'position_seconds' => 600,
                    'duration_seconds' => 45,
                ],
            ],
        ]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            ['position_seconds' => 600, 'type' => 'promo', 'category_id' => null],
        ]);

        $I->sendGET(
            '/api/station/' . $station->id . '/clock-wheels/schedule'
            . '?start=2026-05-01&end=2026-05-31'
        );
        $I->seeResponseCodeIs(200);

        $events = $I->grabDataFromResponseByJsonPath('$')[0];
        $I->assertNotEmpty($events);

        $matching = array_filter(
            $events,
            static fn (array $event): bool => str_contains($event['edit_url'] ?? '', '/clock-wheel/' . $wheelId)
        );
        $I->assertNotEmpty($matching, 'Schedule feed should include edit_url for the clock wheel.');
    }
}
