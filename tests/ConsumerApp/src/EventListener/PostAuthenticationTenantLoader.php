<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Http\TenantRequestContextLoaderInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: -32)]
final readonly class PostAuthenticationTenantLoader
{
    public function __construct(private TenantRequestContextLoaderInterface $loader)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->loader->load($event->getRequest());
        }
    }
}
