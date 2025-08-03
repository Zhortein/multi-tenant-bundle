<?php

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Bridge\Doctrine\Middleware\ConnectionMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverMiddleware;
use Doctrine\DBAL\Configuration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Zhortein\MultiTenantBundle\Context\TenantContext;

class TenantAwareConnectionFactory
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantConnectionResolverInterface $resolver,
        #[Autowire(service: 'doctrine.dbal.configuration')]
        private readonly Configuration $configuration,
    ) {}

    public function createConnection(array $params, ?string $name = null): Connection
    {
        $tenant = $this->context->getTenant();

        if ($tenant) {
            $tenantParams = $this->resolver->resolveParameters($tenant);
            $params = array_merge($params, $tenantParams);
        }

        // Doctrine needs to resolve URL to full params (optional)
        if (isset($params['url'])) {
            $parser = new DsnParser();
            $params = array_merge($parser->parse($params['url']), $params);
        }

        return DriverManager::getConnection($params, $this->configuration);
    }
}
