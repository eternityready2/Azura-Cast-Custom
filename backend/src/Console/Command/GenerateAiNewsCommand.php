<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Service\AiNewsGenerator;
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

    public function __construct(
        private readonly AiNewsGenerator $generator,
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

            $io->text(
                $force && !$backendConfig->ai_news_enabled
                    ? '<comment>Force mode: ignoring disabled state.</comment>'
                    : ''
            );

            try {
                $this->generator->generate($station, $force);
                $io->text('<info>Generation succeeded.</info>');
                $successCount++;
            } catch (Throwable $e) {
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

    /**
     * @return Station[]
     */
    private function resolveStations(?string $stationName): array
    {
        $repo = $this->em->getRepository(Station::class);

        if (null !== $stationName) {
            $station = $repo->findOneBy(['short_name' => $stationName])
                ?? $repo->find($stationName);

            return (null !== $station) ? [$station] : [];
        }

        return $repo->findAll();
    }
}
