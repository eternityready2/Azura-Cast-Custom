<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Container\EntityManagerAwareTrait;
use App\Service\AiNewsGenerator;
use App\Service\StationDiagnostics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'azuracast:ai-news:generate',
    description: 'Generate an AI news bulletin for stations with the feature enabled.',
)]
final class GenerateAiNewsCommand extends CommandAbstract
{
    use EntityManagerAwareTrait;
    use ResolvesStationArgumentTrait;

    public function __construct(
        private readonly AiNewsGenerator $generator,
        private readonly StationDiagnostics $diagnostics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'station-name',
            InputArgument::OPTIONAL,
            'Generate news for a single station by short name or ID.'
        );

        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Force generation even when AI news is disabled for a station.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('AI News Bulletin Generator');

        $stationName = $input->getArgument('station-name');
        $force = (bool) $input->getOption('force');

        try {
            $stations = $this->resolveStations($stationName);
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return 1;
        }

        if (empty($stations)) {
            $io->warning('No matching stations found.');
            return 0;
        }

        $successCount = 0;
        $skipCount = 0;

        foreach ($stations as $station) {
            $io->section($station->name);

            $backendConfig = $station->backend_config;

            if (!$force && !$backendConfig->ai_news_enabled) {
                $io->text('<comment>AI news disabled — skipped.</comment>');
                $skipCount++;
                continue;
            }

            if ($force && !$backendConfig->ai_news_enabled) {
                $io->text('<comment>Force mode: ignoring disabled state.</comment>');
            }

            $previousGeneratedAt = $station->ai_news_last_generation_time?->getTimestamp();

            try {
                $this->generator->generate($station, $force);
                $currentGeneratedAt = $station->ai_news_last_generation_time?->getTimestamp();

                if (null !== $currentGeneratedAt && $currentGeneratedAt !== $previousGeneratedAt) {
                    $this->diagnostics->info(
                        $station,
                        'ai news',
                        'AI News bulletin generation completed.',
                        [
                            'forced' => $force,
                            'generation_time' => $currentGeneratedAt,
                            'status' => $station->ai_news_last_generation_status,
                        ]
                    );
                } else {
                    $this->diagnostics->info(
                        $station,
                        'ai news',
                        'AI News generation was checked but intentionally skipped.',
                        [
                            'forced' => $force,
                            'status' => $station->ai_news_last_generation_status,
                        ]
                    );
                }

                $io->text('<info>Generation succeeded.</info>');
                $successCount++;
            } catch (Throwable $e) {
                $error = str_replace($station->getFilteredPasswords(), '(PASSWORD)', $e->getMessage());
                $this->diagnostics->error(
                    $station,
                    'ai news',
                    'AI News bulletin generation failed.',
                    [
                        'forced' => $force,
                        'error' => $error,
                    ]
                );

                $io->error(sprintf('Failed: %s', $e->getMessage()));
                return 1;
            }
        }

        $io->success(
            sprintf(
                'Processed %d station(s): %d generated, %d skipped.',
                count($stations),
                $successCount,
                $skipCount
            )
        );

        return 0;
    }
}
