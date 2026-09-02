<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;

/**
 * Terminal cleanup runs at kernel.terminate, after a real runtime has sent
 * streamed response content. The next main request remains an independent
 * mandatory barrier when terminate is not invoked.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -1024)]
final readonly class TenantRequestTerminationListener
{
    public function __construct(private TenantStateResetterInterface $stateResetter)
    {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if ($event->isMainRequest()) {
            try {
                $this->stateResetter->reset();
            } catch (\Throwable $cleanupFailure) {
                $operationFailure = $event->getRequest()->attributes->get(TenantRequestExceptionTracker::REQUEST_ATTRIBUTE);
                throw new TenantContextTransitionException('HTTP tenant state could not be reset at request termination.', 0, $operationFailure instanceof \Throwable ? $operationFailure : $cleanupFailure, null, $operationFailure instanceof \Throwable ? $cleanupFailure : null);
            }
        }
    }
}
