<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Radio\AutoDJ\LinearLogBuilder;
use App\Utilities\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Projects and commits a full linear playout log ahead of real-time playback,
 * reusing the same selection pipeline (playlists, clock wheels, schedules,
 * top-of-hour protection) as live AutoDJ queue building -- just run with a much
 * larger time horizon. Runs manually against any station regardless of that
 * station's `linear_log_enabled` setting; {@see \App\Sync\Task\BuildLinearLogTask}
 * is what runs this automatically for stations that have opted in.
 *
 * This does not replace live queue building: it fills the same station_queue
 * table further out, and each future run (live or via this command again)
 * re-times existing rows to absorb any drift before adding anything new, so
 * the log stays accurate as real playback catches up.
 */
#[AsCommand(
    name: 'azuracast:radio:build-linear-log',
    description: 'Build a linear playout log 24 to 48 hours ahead for one or all stations.',
)]
final class BuildLinearLogCommand extends CommandAbstract
{
    private const int DEFAULT_HOURS = 24;

    public function __construct(
        private readonly StationRepository $stationRepo,
        private readonly LinearLogBuilder $linearLogBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station-name', InputArgument::OPTIONAL)
            ->addOption(
                'hours',
                null,
                InputOption::VALUE_REQUIRED,
                'How many hours ahead to build the log (24 to 48).',
                (string)self::DEFAULT_HOURS,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $hours = LinearLogBuilder::normalizeHours(
            Types::int($input->getOption('hours'), self::DEFAULT_HOURS)
        );

        $stationName = Types::stringOrNull($input->getArgument('station-name'));

        if (!empty($stationName)) {
            $station = $this->stationRepo->findByIdentifier($stationName);

            if (!$station instanceof Station) {
                $io->error('Station not found.');
                return 1;
            }

            $stations = [$station];
        } else {
            $stations = $this->stationRepo->fetchAll();
        }

        $io->section(sprintf('Building linear log %d hour(s) ahead...', $hours));
        $io->progressStart(count($stations));

        $failures = 0;

        foreach ($stations as $station) {
            if (!$station->supportsAutoDjQueue()) {
                $io->progressAdvance();
                continue;
            }

            try {
                $this->linearLogBuilder->build($station, $hours);
            } catch (Throwable $e) {
                $failures++;
                $io->warning($station . ': ' . $e->getMessage());
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if ($failures > 0) {
            $io->warning(sprintf('%d station(s) failed to build.', $failures));
        }

        $io->success('Linear log build complete.');
        return 0;
    }
}
