<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Keeps the primary HTTP failure available if terminal cleanup also fails. */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2048)]
final readonly class TenantRequestExceptionTracker
{
    public const string REQUEST_ATTRIBUTE = '_zhortein_tenant_operation_failure';

    public function onKernelException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::REQUEST_ATTRIBUTE, $event->getThrowable());
        }
    }
}
