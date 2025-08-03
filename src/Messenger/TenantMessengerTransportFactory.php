<?php

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Transport\Dsn;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface as MessengerFactory;
use Symfony\Component\Messenger\Transport\TransportInterface as MessengerTransport;

final class TenantMessengerTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly iterable $factories, // Injected all Messenger factories
    ) {
    }

    public function createTransport(Dsn $dsn, array $options): TransportInterface
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof TenantInterface) {
            throw new \RuntimeException('No tenant resolved for messenger transport.');
        }

        $tenantDsn = $tenant->getMessengerDsn();
        if (!$tenantDsn) {
            throw new \RuntimeException('No messenger DSN configured for tenant.');
        }

        $resolved = Dsn::fromString($tenantDsn);

        foreach ($this->factories as $factory) {
            if ($factory->supports($resolved, $options)) {
                return $factory->createTransport($resolved, $options);
            }
        }

        throw new \RuntimeException('No messenger transport factory supports this tenant DSN.');
    }

    public function supports(Dsn $dsn, array $options): bool
    {
        return 'tenant' === $dsn->getScheme();
    }
}
