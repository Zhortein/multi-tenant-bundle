<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Factory for creating tenant-aware database connections.
 *
 * This factory creates database connections that are configured
 * based on the current tenant context, allowing for per-tenant
 * database configurations.
 */
final readonly class TenantAwareConnectionFactory
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantConnectionResolverInterface $connectionResolver,
        #[Autowire(service: 'doctrine.dbal.configuration')]
        private Configuration $configuration,
    ) {
    }

    /**
     * Creates a database connection for the current tenant.
     *
     * @param array<string, mixed> $params Base connection parameters
     * @param string|null          $name   Connection name (unused but kept for compatibility)
     *
     * @return Connection The configured database connection
     *
     * @throws Exception
     */
    public function createConnection(array $params, ?string $name = null): Connection
    {
        $tenant = $this->tenantContext->getTenant();

        if (null !== $tenant) {
            $tenantParams = $this->connectionResolver->resolveParameters($tenant);
            $params = array_merge($params, $tenantParams);
        }

        // Parse DSN URL if provided
        if (isset($params['url']) && is_string($params['url'])) {
            $parser = new DsnParser();
            $parsedParams = $parser->parse($params['url']);
            $params = array_merge($parsedParams, $params);
            unset($params['url']); // Remove URL after parsing
        }

        return DriverManager::getConnection($params, $this->configuration);
    }
}
