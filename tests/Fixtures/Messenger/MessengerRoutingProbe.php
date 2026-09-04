<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

final class MessengerRoutingProbe
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
