<?php

namespace Zhortein\MultiTenantBundle\Messenger;

use Zhortein\MultiTenantBundle\Resolver\TenantConfigurationResolver;

final class TenantMessengerConfigurator
{
    public function __construct(
        private readonly TenantConfigurationResolver $resolver,
    ) {}

    public function getTransportDsn(): string
    {
        return $this->resolver->get('messenger_transport_dsn', 'sync://');
    }

    public function getBusName(): string
    {
        return $this->resolver->get('messenger_bus', 'messenger.bus.default');
    }

    public function getDelay(?string $transport = null): int
    {
        return (int) $this->resolver->get("messenger_delay_{$transport}", 0);
    }
}
