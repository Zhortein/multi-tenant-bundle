<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\SynchronousTenantMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SynchronousTenantMessageHandler
{
    public function __construct(private RoutingProbe $probe)
    {
    }

    public function __invoke(SynchronousTenantMessage $message): void
    {
        $this->probe->record();
    }
}
