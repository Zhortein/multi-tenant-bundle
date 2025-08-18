<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Configures PostgreSQL session variables for Row-Level Security (RLS).
 *
 * This service sets the tenant context in PostgreSQL session variables
 * to enable Row-Level Security policies for defense-in-depth protection.
 * It works both for HTTP requests and Messenger workers.
 */
final readonly class TenantSessionConfigurator implements MiddlewareInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private Connection $connection,
        private TenantRegistryInterface $tenantRegistry,
        private bool $rlsEnabled,
        private string $sessionVariable = 'app.tenant_id',
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * HTTP request listener that sets tenant session variable.
     *
     * This listener runs after tenant resolution to configure the database
     * session with the current tenant ID for RLS policies.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onKernelRequest(RequestEvent $event): void
    {
        // Only process main requests and when RLS is enabled
        if (!$event->isMainRequest() || !$this->rlsEnabled) {
            return;
        }

        $this->configureTenantSession();
    }

    /**
     * Messenger middleware that restores tenant session variable.
     *
     * This middleware extracts tenant information from message stamps
     * and configures the database session for worker processes.
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->rlsEnabled) {
            return $stack->next()->handle($envelope, $stack);
        }

        $sessionConfigured = false;

        // Extract tenant information from message stamps
        $tenantStamp = $envelope->last(TenantStamp::class);

        if ($tenantStamp instanceof TenantStamp) {
            // Restore tenant context from stamp
            $tenant = $this->tenantRegistry->findById($tenantStamp->getTenantId());

            if (null !== $tenant) {
                $this->tenantContext->setTenant($tenant);
                $this->configureTenantSession();
                $sessionConfigured = true;
            }
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            // Clear tenant context after message processing
            $this->tenantContext->clear();

            // Only clear session if it was configured
            if ($sessionConfigured) {
                $this->clearTenantSession();
            }
        }
    }

    /**
     * Manually configures the PostgreSQL session variable with current tenant ID.
     *
     * This method can be called directly to configure the session when needed.
     */
    public function setConfig(): void
    {
        $this->configureTenantSession();
    }

    /**
     * Configures the PostgreSQL session variable with current tenant ID.
     */
    private function configureTenantSession(): void
    {
        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            $this->logger?->debug('No tenant context available for RLS configuration');

            return;
        }

        try {
            // Check if we're using PostgreSQL
            if (!$this->isPostgreSQL()) {
                $this->logger?->debug('RLS is only supported with PostgreSQL, skipping session configuration');

                return;
            }

            $tenantId = (string) $tenant->getId();

            // Set the session variable for RLS policies
            $this->connection->executeStatement(
                'SELECT set_config(?, ?, true)',
                [$this->sessionVariable, $tenantId]
            );

            $this->logger?->debug('Configured PostgreSQL session variable for RLS', [
                'tenant_id' => $tenantId,
                'tenant_slug' => $tenant->getSlug(),
                'session_variable' => $this->sessionVariable,
            ]);
        } catch (Exception $exception) {
            $this->logger?->error('Failed to configure PostgreSQL session variable for RLS', [
                'exception' => $exception->getMessage(),
                'tenant_id' => $tenant->getId(),
                'session_variable' => $this->sessionVariable,
            ]);
        }
    }

    /**
     * Clears the PostgreSQL session variable.
     */
    private function clearTenantSession(): void
    {
        try {
            if ($this->isPostgreSQL()) {
                $this->connection->executeStatement(
                    'SELECT set_config(?, NULL, true)',
                    [$this->sessionVariable]
                );

                $this->logger?->debug('Cleared PostgreSQL session variable for RLS', [
                    'session_variable' => $this->sessionVariable,
                ]);
            }
        } catch (Exception $exception) {
            $this->logger?->warning('Failed to clear PostgreSQL session variable for RLS', [
                'exception' => $exception->getMessage(),
                'session_variable' => $this->sessionVariable,
            ]);
        }
    }

    /**
     * Checks if the current database connection is PostgreSQL.
     */
    private function isPostgreSQL(): bool
    {
        return str_contains($this->connection->getDatabasePlatform()->getName(), 'postgresql');
    }
}
