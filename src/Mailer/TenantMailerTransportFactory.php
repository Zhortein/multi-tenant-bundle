<?php

namespace Zhortein\MultiTenantBundle\Mailer;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

final class TenantMailerTransportFactory extends AbstractTransportFactory
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof TenantInterface) {
            throw new \RuntimeException('No tenant resolved for mailer transport.');
        }

        $tenantDsn = $tenant->getMailerDsn();
        if (!$tenantDsn) {
            throw new \RuntimeException("No mailer DSN configured for tenant.");
        }

        $resolved = Dsn::fromString($tenantDsn);
        return $this->doCreate($resolved);
    }

    public function supports(Dsn $dsn): bool
    {
        return 'tenant' === $dsn->getScheme();
    }

    public function getSupportedSchemes(): array
    {
        return ['tenant'];
    }
}
