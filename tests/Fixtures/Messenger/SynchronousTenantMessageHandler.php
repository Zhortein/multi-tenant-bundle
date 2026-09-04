<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\SynchronousTenantMessage;

#[AsMessageHandler]
final readonly class SynchronousTenantMessageHandler
{
    public function __construct(private MessengerRoutingProbe $probe)
    {
    }

    public function __invoke(SynchronousTenantMessage $message): void
    {
        $this->probe->record();
    }
}
