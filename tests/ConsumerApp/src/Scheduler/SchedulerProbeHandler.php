<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\ScheduledGlobalMessage;
use App\Message\ScheduledTenantMessage;
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

    public function __invoke(ScheduledGlobalMessage|ScheduledTenantMessage $message): void
    {
        $this->probe->record($message->label, $this->tenantContext->getTenant()?->getSlug());
        if ('consumer-scheduler-failure' === $message->label) {
            throw new \RuntimeException('Controlled application Worker failure.');
        }
    }
}
