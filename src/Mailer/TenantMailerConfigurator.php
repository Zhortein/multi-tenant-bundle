<?php

namespace Zhortein\MultiTenantBundle\Mailer;

use Zhortein\MultiTenantBundle\Resolver\TenantConfigurationResolver;

final class TenantMailerConfigurator
{
    public function __construct(
        private TenantConfigurationResolver $configResolver,
        private TransportInterface $transport
    ) {}

    public function configure(): void
    {
        $dsn = $this->configResolver->get('mailer_dsn');
        if ($dsn) {
            // Hypothèse : une méthode `setDsn()` existe ou service personnalisé requis
            $this->transport->setDsn($dsn);
        }
    }
}
