<?php

namespace Zhortein\MultiTenantBundle\Messenger;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final class TenantMessengerTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly iterable $factories, // Injected all Messenger factories
    ) {
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof TenantInterface) {
            throw new \RuntimeException('No tenant resolved for messenger transport.');
        }

        $tenantDsn = $tenant->getMessengerDsn();
        if (!$tenantDsn) {
            throw new \RuntimeException('No messenger DSN configured for tenant.');
        }

        foreach ($this->factories as $factory) {
            if ($factory->supports($tenantDsn, $options)) {
                return $factory->createTransport($tenantDsn, $options, $serializer);
            }
        }

        throw new \RuntimeException('No messenger transport factory supports this tenant DSN.');
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'tenant://');
    }
}
