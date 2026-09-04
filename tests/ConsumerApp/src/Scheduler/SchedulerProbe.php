<?php

declare(strict_types=1);

namespace App\Scheduler;

final class SchedulerProbe
{
    /** @var list<array{string, string|null}> */
    private array $handled = [];

    public function record(string $label, ?string $tenant): void
    {
        $this->handled[] = [$label, $tenant];
    }

    /** @return list<array{string, string|null}> */
    public function handled(): array
    {
        return $this->handled;
    }
}
