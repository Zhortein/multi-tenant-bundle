<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;

final class TenantConsoleBoundarySubscriber
{
    private bool $errorBoundaryHandled = false;

    public function __construct(private TenantStateResetterInterface $stateResetter)
    {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 2048)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->errorBoundaryHandled = false;
        $this->stateResetter->reset();
    }

    #[AsEventListener(event: ConsoleEvents::ERROR, priority: -2048)]
    public function onError(ConsoleErrorEvent $event): void
    {
        $this->errorBoundaryHandled = true;
        $operationFailure = $event->getError();
        try {
            $this->stateResetter->reset();
        } catch (\Throwable $cleanupFailure) {
            $event->setError(new TenantContextTransitionException(
                'Console tenant state could not be reset after command failure.',
                0,
                $operationFailure,
                null,
                $cleanupFailure,
            ));
        }
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -2048)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->errorBoundaryHandled) {
            $this->errorBoundaryHandled = false;

            return;
        }

        $this->stateResetter->reset();
    }
}
