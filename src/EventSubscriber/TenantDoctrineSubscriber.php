<?php

namespace Zhortein\MultiTenantBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineConnectionSwitcher;

final class TenantDoctrineSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DoctrineConnectionSwitcher $switcher
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 35]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->switcher->switch();
    }
}
