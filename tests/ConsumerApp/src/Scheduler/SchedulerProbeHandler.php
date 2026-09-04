<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\ScheduledGlobalMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

#[AsMessageHandler]
final readonly class SchedulerProbeHandler
{
    public function __construct(
        private SchedulerProbe $probe,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function __invoke(ScheduledGlobalMessage $message): void
    {
        $this->probe->record($message->label, $this->tenantContext->getTenant()?->getSlug());
    }
}
