<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

final class LinearLogPreviewContext
{
    private ?string $runId = null;

    public function begin(): void
    {
        $this->runId = bin2hex(random_bytes(8));
    }

    public function end(): void
    {
        $this->runId = null;
    }

    public function isActive(): bool
    {
        return null !== $this->runId;
    }

    public function cacheKey(string $liveKey): string
    {
        if (null === $this->runId) {
            return $liveKey;
        }

        return 'linear_log_preview.' . $this->runId . '.' . $liveKey;
    }
}
