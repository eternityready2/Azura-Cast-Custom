<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use Codeception\Test\Unit;
use Plugin\TopOfHour\AutoDjRetirementRuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/AutoDjRetirementRuntimeConfiguration.php';

final class TopOfHourRetirementInvariantTest extends Unit
{
    public function testTransportRetirementPurgesPrefetchAndRequiresPostRetirementGeneration(): void
    {
        $station = new Station();
        $station->name = 'TOH Retirement Test';
        $station->short_name = 'toh_retirement_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/toh_retirement_test';

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new AutoDjRetirementRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('dynamic.current()', $config);
        self::assertStringContainsString('request.destroy(force=true, null.get(current))', $config);
        self::assertStringContainsString('queued = dynamic.queue()', $config);
        self::assertStringContainsString('request.destroy(force=true, req)', $config);
        self::assertStringContainsString('dynamic.set_queue([])', $config);
        self::assertStringContainsString('dynamic.skip()', $config);
        self::assertStringContainsString('exclude_song_id', $config);
        self::assertStringNotContainsString('reset_sq_ids', $config);
        self::assertStringContainsString('azuracast.autodj_retirement_generation', $config);
        self::assertStringContainsString(
            'azuracast.autodj_generation() > azuracast.autodj_retirement_generation()',
            $config,
        );
        self::assertStringContainsString('Different AutoDJ song is on air; local retirement quarantine cleared.', $config);
    }

    public function testAudibleProcessedSongIdentityWinsOverAdvancedLeafTransport(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = file_get_contents($root . '/plugins/top_of_hour/src/AutoDjRetirementRuntimeConfiguration.php');
        $gate = file_get_contents($root . '/plugins/top_of_hour/src/RigidScheduleRuntimeConfiguration.php');

        self::assertIsString($runtime);
        self::assertStringContainsString('azuracast.autodj_retirement_song_hint', $runtime);
        self::assertStringContainsString('def azuracast.set_autodj_retirement_song_hint(song_id)', $runtime);
        self::assertStringContainsString('hinted_song_id = azuracast.autodj_retirement_song_hint()', $runtime);
        self::assertStringContainsString('if hinted_song_id != "" then', $runtime);
        self::assertStringContainsString('elsif null.defined(current) then', $runtime);

        $hintBranch = strpos($runtime, 'if hinted_song_id != "" then');
        $leafFallback = strpos($runtime, 'elsif null.defined(current) then');
        self::assertIsInt($hintBranch);
        self::assertIsInt($leafFallback);
        self::assertTrue($hintBranch < $leafFallback, 'Audible hint must be evaluated before request.dynamic current metadata.');

        self::assertIsString($gate);
        self::assertStringContainsString('radio_before_broadcast_clock_gate.last_metadata()', $gate);
        self::assertStringContainsString('azuracast.set_autodj_retirement_song_hint(audible_song_id)', $gate);
        self::assertStringContainsString('broadcast_clock_capture_retirement_song()', $gate);

        $capture = strpos($gate, 'broadcast_clock_capture_retirement_song()');
        $discard = strpos($gate, 'azuracast.discard_autodj_current()');
        self::assertIsInt($capture);
        self::assertIsInt($discard);
        self::assertTrue($capture < $discard, 'Audible metadata must be captured before the leaf transport is destroyed.');
    }

    public function testBackendFinalHandoffAndEverySelectorAttemptExcludeRetiredSong(): void
    {
        $root = dirname(__DIR__, 2);

        $service = file_get_contents($root . '/backend/src/Radio/AutoDJ/AutoDjRetirementService.php');
        $annotations = file_get_contents($root . '/backend/src/Radio/AutoDJ/Annotations.php');
        $nextSong = file_get_contents($root . '/backend/src/Radio/Backend/Liquidsoap/Command/NextSongCommand.php');
        $requests = file_get_contents($root . '/backend/src/Entity/Repository/StationRequestRepository.php');
        $guard = file_get_contents($root . '/plugins/top_of_hour/src/RetiredSongQueueGuard.php');

        self::assertIsString($service);
        self::assertStringContainsString('sq.song_id != :excludedSongId', $service);
        self::assertStringContainsString('SET sq.sent_to_autodj = 0', $service);
        self::assertStringContainsString('AND sq.sent_to_autodj = 1', $service);
        self::assertStringContainsString("->andWhere('sq.is_played = 0')", $service);
        self::assertStringContainsString('$queueRow->request->played_at = null;', $service);

        self::assertIsString($annotations);
        self::assertStringContainsString('$this->retirement->getNextToSendToAutoDj($station)', $annotations);

        self::assertIsString($nextSong);
        self::assertStringContainsString('if ($asAutoDj) {', $nextSong);
        self::assertStringContainsString("\$payload['exclude_song_id']", $nextSong);
        self::assertStringContainsString('$this->retirement->activate(', $nextSong);
        self::assertStringContainsString('$activeExcludedSongId = $this->retirement->getExcludedSongId($station);', $nextSong);
        self::assertStringContainsString('$this->queue->buildQueue($station);', $nextSong);

        self::assertIsString($requests);
        self::assertStringContainsString('$excludedSongId = $this->retirement->getExcludedSongId($station);', $requests);
        self::assertStringContainsString('!hash_equals($excludedSongId, $request->track->song_id)', $requests);

        self::assertIsString($guard);
        self::assertStringContainsString("BuildQueue::class => ['guardSelection', -1000]", $guard);
        self::assertStringContainsString('!hash_equals($excludedSongId, $queueRow->song_id)', $guard);
        self::assertStringContainsString('$event->setNextSongs([] !== $filtered ? $filtered : null);', $guard);
    }

    public function testRejoinHasNoTimedFailOpen(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/plugins/top_of_hour/src/RigidScheduleRuntimeConfiguration.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('broadcast_clock_rejoin_waiting = ref(false)', $source);
        self::assertStringContainsString('no fail-open', $source);
        self::assertStringNotContainsString('broadcast_clock_rejoin_deadline', $source);
        self::assertStringNotContainsString('fresh AutoDJ rejoin timed out', $source);
        self::assertStringNotContainsString('time() + 5.0', $source);
    }
}
