<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;

/** Starts every main request from the fail-closed NONE state. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
final readonly class TenantRequestBoundaryListener
{
    public function __construct(private TenantStateResetterInterface $stateResetter)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->stateResetter->reset();
        }
    }
}
