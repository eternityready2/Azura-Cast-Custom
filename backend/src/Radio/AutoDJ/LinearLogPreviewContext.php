<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

final class LinearLogPreviewContext
{
    private bool $active = false;

    public function begin(): void
    {
        $this->active = true;
    }

    public function end(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
