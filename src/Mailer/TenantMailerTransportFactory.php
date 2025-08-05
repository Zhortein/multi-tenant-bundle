<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Mailer;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Transport factory for tenant-aware mailer configuration.
 *
 * This factory creates mailer transports based on tenant-specific DSN settings,
 * with fallback to global configuration when tenant settings are not available.
 */
final class TenantMailerTransportFactory extends AbstractTransportFactory
{
    public function __construct(
        private readonly TenantMailerConfigurator $mailerConfigurator,
        private readonly TransportFactoryInterface $fallbackFactory,
        private readonly ?string $globalDsn = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, null, $logger);
    }

    public function create(Dsn $dsn): TransportInterface
    {
        // Try to get tenant-specific DSN
        $tenantDsn = $this->mailerConfigurator->getMailerDsn($this->globalDsn);

        if (null === $tenantDsn) {
            // No tenant context or DSN, use fallback factory
            return $this->fallbackFactory->create($dsn);
        }

        try {
            $resolvedDsn = Dsn::fromString($tenantDsn);

            return $this->fallbackFactory->create($resolvedDsn);
        } catch (\Exception $e) {
            // Log error and fallback to global DSN
            $this->logger?->warning('Failed to create tenant mailer transport', [
                'tenant_dsn' => $tenantDsn,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackFactory->create($dsn);
        }
    }

    public function supports(Dsn $dsn): bool
    {
        return 'tenant' === $dsn->getScheme();
    }

    protected function getSupportedSchemes(): array
    {
        return ['tenant'];
    }
}
