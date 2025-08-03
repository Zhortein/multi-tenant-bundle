<?php

namespace Zhortein\MultiTenantBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\ManagerEventArgs;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContext;

final class TenantDoctrineFilterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->tenantContext->hasTenant()) {
            return;
        }

        $filters = $this->em->getFilters();

        if (!$filters->isEnabled('tenant_filter')) {
            $filters->enable('tenant_filter');
        }

        $tenant = $this->tenantContext->getTenant();
        $filters->getFilter('tenant_filter')->setParameter('tenant_id', $tenant->getId());
    }
}