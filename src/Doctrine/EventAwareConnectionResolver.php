<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Event\TenantDatabaseSwitchEvent;

/**
 * Event-aware connection resolver that dispatches events during database switching.
 *
 * This resolver extends the default behavior by dispatching events before and after
 * switching database connections, allowing other services to react to tenant changes.
 */
final class EventAwareConnectionResolver implements TenantConnectionResolverInterface
{
    public function __construct(
        private readonly TenantConnectionResolverInterface $innerResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function resolveParameters(TenantInterface $tenant): array
    {
        return $this->innerResolver->resolveParameters($tenant);
    }

    /**
     * {@inheritdoc}
     */
    public function switchToTenantConnection(TenantInterface $tenant): void
    {
        $previousTenant = $this->tenantContext->getTenant();
        $connectionParams = $this->resolveParameters($tenant);

        // Dispatch before switch event
        $beforeEvent = new TenantDatabaseSwitchEvent($tenant, $connectionParams, $previousTenant);
        $this->eventDispatcher->dispatch($beforeEvent, TenantDatabaseSwitchEvent::BEFORE_SWITCH);

        // Perform the actual switch
        $this->innerResolver->switchToTenantConnection($tenant);

        // Dispatch after switch event
        $afterEvent = new TenantDatabaseSwitchEvent($tenant, $connectionParams, $previousTenant);
        $this->eventDispatcher->dispatch($afterEvent, TenantDatabaseSwitchEvent::AFTER_SWITCH);
    }
}