<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Database\TenantSessionConfigurator;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScopeInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantStampException;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Exception\UnknownTenantException;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Middleware that restores tenant context in worker processes.
 *
 * This middleware extracts tenant information from TenantStamp
 * and restores the tenant context for message handlers, including
 * configuring database session variables for Row-Level Security.
 */
final readonly class TenantWorkerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantRegistryInterface $tenantRegistry,
        private ?TenantSessionConfigurator $sessionConfigurator = null,
        private ?GlobalDoctrineScopeInterface $globalDoctrineScope = null,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $previousTenant = $this->tenantContext->getTenant();

        $result = null;
        $operationFailure = null;
        try {
            // A reused worker must start without state from the previous message.
            $this->tenantContext->clear();
            $this->sessionConfigurator?->clearConfig();

            $message = $envelope->getMessage();
            $tenantAware = $message instanceof TenantAwareMessageInterface;
            $global = $message instanceof GlobalMessageInterface;
            if ($tenantAware === $global) {
                throw new UnclassifiedMessageException($tenantAware ? 'A message cannot be both tenant-aware and global.' : 'A received message must implement TenantAwareMessageInterface or GlobalMessageInterface.');
            }

            $stamps = $envelope->all(TenantStamp::class);
            if ($global) {
                if ([] !== $stamps) {
                    throw new TenantMismatchException('A global message cannot carry a TenantStamp.');
                }

                $result = null !== $this->globalDoctrineScope
                    ? $this->globalDoctrineScope->run(fn (): Envelope => $stack->next()->handle($envelope, $stack))
                    : $stack->next()->handle($envelope, $stack);
            } else {
                if ([] === $stamps) {
                    throw new MissingTenantStampException('A received tenant-aware message requires a TenantStamp.');
                }

                $tenantId = $stamps[0]->getTenantId();
                foreach ($stamps as $stamp) {
                    if ($stamp->getTenantId() !== $tenantId) {
                        throw new TenantMismatchException('A received message carries contradictory TenantStamps.');
                    }
                }

                $tenant = $this->tenantRegistry->findById($tenantId);
                if (null === $tenant) {
                    throw new UnknownTenantException(sprintf('Tenant "%s" carried by the message is unavailable.', $tenantId));
                }

                $this->tenantContext->setTenant($tenant);
                $this->sessionConfigurator?->setConfig();

                $result = $stack->next()->handle($envelope, $stack);
            }
        } catch (\Throwable $exception) {
            $operationFailure = $exception;
        }

        try {
            $this->tenantContext->clear();
            $this->sessionConfigurator?->clearConfig();
            if (null !== $previousTenant) {
                $this->tenantContext->setTenant($previousTenant);
                $this->sessionConfigurator?->setConfig();
            }
        } catch (\Throwable $cleanupFailure) {
            throw new TenantContextTransitionException('Messenger tenant state could not be restored after handling.', 0, $operationFailure ?? $cleanupFailure, null, $operationFailure ? $cleanupFailure : null);
        }

        if (null !== $operationFailure) {
            throw $operationFailure;
        }

        return $result;
    }
}
