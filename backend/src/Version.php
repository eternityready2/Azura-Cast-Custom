<?php

declare(strict_types=1);

namespace App;

use App\Enums\ReleaseChannel;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Process\Process;
use Throwable;

final class Version
{
    /** @var string La versión base que Nathan quiere mostrar */
    public const STABLE_VERSION = '0.23.4';

    private string $repoDir;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Environment $environment,
    ) {
        $this->repoDir = $environment->getBaseDirectory();
    }

    public function getReleaseChannelEnum(): ReleaseChannel
    {
        if ($this->environment->isDocker()) {
            return $this->environment->getReleaseChannelEnum();
        }

        $details = $this->getDetails();
        return ('stable' === $details['branch'])
            ? ReleaseChannel::Stable
            : ReleaseChannel::RollingRelease;
    }

    public function getDetails(): array
    {
        static $details;

        if (!$details) {
            $details = $this->cache->get('app_version_details');

            if (empty($details)) {
                $rawDetails = $this->getRawDetails();

                $details = [
                    'commit' => $rawDetails['commit'],
                    'commit_short' => substr($rawDetails['commit'] ?? '', 0, 7),
                    'branch' => $rawDetails['branch'],
                ];

                if (!empty($rawDetails['commit_date_raw'])) {
                    $commitDate = CarbonImmutable::parse($rawDetails['commit_date_raw'], Time::getUtc());
                    $details['commit_timestamp'] = $commitDate->getTimestamp();
                    $details['commit_date'] = $commitDate->format('Y-m-d G:i');
                } else {
                    $details['commit_timestamp'] = 0;
                    $details['commit_date'] = 'N/A';
                }

                $ttl = $this->environment->isProduction() ? 86400 : 600;
                $this->cache->set('app_version_details', $details, $ttl);
            }
        }

        return $details;
    }

    private function getRawDetails(): array
    {
        if (is_file($this->repoDir . '/.gitinfo')) {
            $fileContents = file_get_contents($this->repoDir . '/.gitinfo');
            if (!empty($fileContents)) {
                try {
                    $gitInfo = Dotenv::parse($fileContents);
                    return [
                        'commit' => $gitInfo['COMMIT_LONG'] ?? null,
                        'commit_date_raw' => $gitInfo['COMMIT_DATE'] ?? null,
                        'branch' => $gitInfo['BRANCH'] ?? null,
                    ];
                } catch (Throwable) { }
            }
        }
        if (is_file($this->repoDir . '/.version')) {
            $commit = trim(file_get_contents($this->repoDir . '/.version'));
            if (!empty($commit)) {
                return [
                    'commit' => $commit,
                    'commit_date_raw' => null,
                    'branch' => 'main',
                ];
            }
        }

        if (is_dir($this->repoDir . '/.git')) {
            return [
                'commit' => $this->runProcess(['git', 'log', '--pretty=%H', '-n1', 'HEAD']),
                'commit_date_raw' => $this->runProcess(['git', 'log', '-n1', '--pretty=%ci', 'HEAD']),
                'branch' => $this->runProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], 'main'),
            ];
        }

        return [
            'commit' => null,
            'commit_date_raw' => null,
            'branch' => null,
        ];
    }

    private function runProcess(array $proc, string $default = ''): string
    {
        $process = new Process($proc);
        $process->setWorkingDirectory($this->repoDir);
        $process->run();

        if (!$process->isSuccessful()) {
            return $default;
        }

        return trim($process->getOutput());
    }

    public function getVersionText(bool $asHtml = true): string
    {
        $details = $this->getDetails();
        $releaseChannel = $this->getReleaseChannelEnum();

        if (ReleaseChannel::RollingRelease === $releaseChannel) {
            $versionNum = 'v' . self::STABLE_VERSION;

            if ($asHtml) {
                $commitLink = 'https://github.com/eternityready2/Azura-Cast-Custom/commit/' . $details['commit'];
                return sprintf(
                    'Release <strong>%s</strong> #<a href="%s" target="_blank">%s</a>',
                    $versionNum,
                    $commitLink,
                    $details['commit_short']
                );
            }

            return 'Release ' . $versionNum . ' (#' . $details['commit_short'] . ')';
        }

        return 'v' . self::STABLE_VERSION . ' Stable';
    }

    public function getCommitHash(): ?string
    {
        $details = $this->getDetails();
        return $details['commit'];
    }

    public function getCommitShort(): string
    {
        $details = $this->getDetails();
        return $details['commit_short'];
    }

    public function getVersion(): string
    {
        return self::STABLE_VERSION;
    }
}