<?php

declare(strict_types=1);

namespace App\Messenger;

final class RoutingProbe
{
    private int $handled = 0;

    public function record(): void
    {
        ++$this->handled;
    }

    public function handledCount(): int
    {
        return $this->handled;
    }
}
