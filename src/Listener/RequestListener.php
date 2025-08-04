<?php

namespace Zhortein\MultiTenantBundle\Listener;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Event\TenantResolvedEvent;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

final class RequestListener
{
    public function __construct(
        private readonly TenantResolverInterface $resolver,
        private readonly TenantContext $tenantContext,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($this->tenantContext->hasTenant()) {
            return;
        }

        $tenant = $this->resolver->resolveTenant($request);

        if (null !== $tenant) {
            $this->tenantContext->setTenant($tenant);
            $this->dispatcher->dispatch(new TenantResolvedEvent($tenant));
        }
    }
}
