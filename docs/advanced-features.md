# Advanced Features

This document covers the advanced features of the Zhortein Multi-Tenant Bundle that extend beyond basic tenant resolution and database filtering.

## Table of Contents

- [Header-Based Tenant Resolution](#header-based-tenant-resolution)
- [Database Events](#database-events)
- [Tenant-Scoped Services](#tenant-scoped-services)
- [Entity Manager Factory](#entity-manager-factory)
- [Advanced Commands](#advanced-commands)
- [Custom Connection Resolvers](#custom-connection-resolvers)

## Header-Based Tenant Resolution

The bundle supports resolving tenants from HTTP headers, which is particularly useful for API-based applications.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'header'
    header:
        name: 'X-Tenant-Slug'  # Default header name
```

### Usage

Clients can specify the tenant by including the header in their requests:

```bash
curl -H "X-Tenant-Slug: acme" https://api.example.com/users
```

### Custom Header Names

You can customize the header name used for tenant resolution:

```yaml
zhortein_multi_tenant:
    resolver: 'header'
    header:
        name: 'X-Custom-Tenant-ID'
```

## Database Events

The bundle dispatches events when switching between tenant databases, allowing you to hook into the tenant switching process.

### Configuration

```yaml
zhortein_multi_tenant:
    events:
        dispatch_database_switch: true  # Default: true
```

### Event Types

- `TenantDatabaseSwitchEvent::BEFORE_SWITCH` - Dispatched before switching to a tenant database
- `TenantDatabaseSwitchEvent::AFTER_SWITCH` - Dispatched after switching to a tenant database

### Creating Event Listeners

```php
<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Zhortein\MultiTenantBundle\Event\TenantDatabaseSwitchEvent;

#[AsEventListener(event: TenantDatabaseSwitchEvent::BEFORE_SWITCH)]
class TenantDatabaseSwitchListener
{
    public function onBeforeSwitch(TenantDatabaseSwitchEvent $event): void
    {
        $tenant = $event->getTenant();
        $previousTenant = $event->getPreviousTenant();
        
        // Perform cleanup or setup operations
        $this->logger->info('Switching from tenant {previous} to {current}', [
            'previous' => $previousTenant?->getSlug(),
            'current' => $tenant->getSlug(),
        ]);
    }
    
    #[AsEventListener(event: TenantDatabaseSwitchEvent::AFTER_SWITCH)]
    public function onAfterSwitch(TenantDatabaseSwitchEvent $event): void
    {
        $tenant = $event->getTenant();
        
        // Initialize tenant-specific services
        $this->initializeTenantServices($tenant);
    }
}
```

## Tenant-Scoped Services

The bundle provides a tenant scope for the dependency injection container, allowing services to be scoped to specific tenants.

### Configuration

```yaml
zhortein_multi_tenant:
    container:
        enable_tenant_scope: true  # Default: false
```

### Defining Tenant-Scoped Services

```yaml
# config/services.yaml
services:
    App\Service\TenantSpecificService:
        scope: tenant
        # Service will be recreated for each tenant
```

### Using Tenant-Scoped Services

```php
<?php

namespace App\Controller;

use App\Service\TenantSpecificService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class DashboardController extends AbstractController
{
    public function index(TenantSpecificService $service): Response
    {
        // Service instance is specific to the current tenant
        $data = $service->getTenantData();
        
        return $this->render('dashboard/index.html.twig', [
            'data' => $data,
        ]);
    }
}
```

## Entity Manager Factory

The `TenantEntityManagerFactory` allows you to create entity managers for specific tenants programmatically.

### Usage

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerFactory;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

class MultiTenantReportService
{
    public function __construct(
        private TenantEntityManagerFactory $entityManagerFactory,
        private TenantRegistryInterface $tenantRegistry,
    ) {
    }
    
    public function generateCrossTenantReport(): array
    {
        $tenants = $this->tenantRegistry->getAll();
        $report = [];
        
        foreach ($tenants as $tenant) {
            $em = $this->entityManagerFactory->createForTenant($tenant);
            
            // Query data for this specific tenant
            $userCount = $em->getRepository(User::class)->count([]);
            
            $report[$tenant->getSlug()] = [
                'users' => $userCount,
                // ... other metrics
            ];
            
            $em->close(); // Important: close the entity manager
        }
        
        return $report;
    }
}
```

### Batch Operations

```php
public function performBatchOperation(): void
{
    $tenants = $this->tenantRegistry->getAll();
    $entityManagers = $this->entityManagerFactory->createForTenants($tenants);
    
    foreach ($entityManagers as $tenantSlug => $em) {
        // Perform operations on each tenant's database
        $this->performOperationForTenant($em, $tenantSlug);
        $em->close();
    }
}
```

## Advanced Commands

The bundle provides several advanced commands for managing tenant databases and data.

### Schema Management

```bash
# Create database schema for all tenants
php bin/console tenant:schema:create

# Create schema for specific tenant
php bin/console tenant:schema:create --tenant=acme

# Drop schema for all tenants (with confirmation)
php bin/console tenant:schema:drop

# Drop schema with force flag (skip confirmation)
php bin/console tenant:schema:drop --force

# Dump SQL instead of executing
php bin/console tenant:schema:create --dump-sql
```

### Migration Management

```bash
# Run migrations for all tenants
php bin/console tenant:migrate

# Run migrations for specific tenant
php bin/console tenant:migrate --tenant=acme

# Dry run (show SQL without executing)
php bin/console tenant:migrate --dry-run

# Allow execution even if no migrations found
php bin/console tenant:migrate --allow-no-migration
```

### Fixtures Management

```bash
# Load fixtures for all tenants
php bin/console tenant:fixtures:load

# Load fixtures for specific tenant
php bin/console tenant:fixtures:load --tenant=acme

# Append fixtures instead of purging
php bin/console tenant:fixtures:load --append

# Load specific fixture groups
php bin/console tenant:fixtures:load --group=demo --group=test

# Exclude tables from purging
php bin/console tenant:fixtures:load --purge-exclusions=audit_log
```

## Custom Connection Resolvers

You can create custom connection resolvers for complex tenant database configurations.

### Creating a Custom Resolver

```php
<?php

namespace App\Doctrine;

use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

class CustomConnectionResolver implements TenantConnectionResolverInterface
{
    public function __construct(
        private array $defaultParameters,
        private string $databasePrefix = 'tenant_',
    ) {
    }
    
    public function resolveParameters(TenantInterface $tenant): array
    {
        return array_merge($this->defaultParameters, [
            'dbname' => $this->databasePrefix . $tenant->getSlug(),
            'host' => $tenant->getDatabaseHost() ?? $this->defaultParameters['host'],
            'port' => $tenant->getDatabasePort() ?? $this->defaultParameters['port'],
        ]);
    }
    
    public function switchToTenantConnection(TenantInterface $tenant): void
    {
        // Implement connection switching logic
        // This might involve updating connection parameters,
        // closing existing connections, etc.
    }
}
```

### Registering the Custom Resolver

```yaml
# config/services.yaml
services:
    App\Doctrine\CustomConnectionResolver:
        arguments:
            $defaultParameters: '%doctrine.dbal.default_connection.params%'
            $databasePrefix: 'tenant_'
        
    # Override the default resolver
    Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface:
        alias: App\Doctrine\CustomConnectionResolver
```

### Configuration for Custom Resolvers

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    resolver: 'custom'  # Use custom resolver
    database:
        strategy: 'separate'  # Usually used with separate databases
```

## Performance Considerations

### Entity Manager Lifecycle

When using the entity manager factory, always close entity managers when done:

```php
$em = $this->entityManagerFactory->createForTenant($tenant);
try {
    // Perform operations
    $result = $em->getRepository(Entity::class)->findAll();
} finally {
    $em->close(); // Always close to prevent memory leaks
}
```

### Connection Pooling

For high-traffic applications, consider implementing connection pooling in your custom connection resolver:

```php
class PooledConnectionResolver implements TenantConnectionResolverInterface
{
    private array $connectionPool = [];
    
    public function resolveParameters(TenantInterface $tenant): array
    {
        $tenantId = $tenant->getId();
        
        if (!isset($this->connectionPool[$tenantId])) {
            $this->connectionPool[$tenantId] = $this->createConnection($tenant);
        }
        
        return $this->connectionPool[$tenantId];
    }
}
```

### Caching Considerations

When using tenant-scoped services, be aware of memory usage:

```yaml
zhortein_multi_tenant:
    container:
        enable_tenant_scope: true
    cache:
        ttl: 1800  # Shorter TTL for tenant-scoped applications
```

## Security Considerations

### Header Validation

When using header-based resolution, validate headers to prevent injection attacks:

```php
<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

class SecureHeaderTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface
    {
        $tenantSlug = $request->headers->get('X-Tenant-Slug');
        
        // Validate tenant slug format
        if (!preg_match('/^[a-z0-9-]+$/', $tenantSlug)) {
            return null;
        }
        
        // Additional security checks...
        
        return $this->tenantRegistry->getBySlug($tenantSlug);
    }
}
```

### Database Isolation

Ensure proper database isolation when using separate database strategy:

```php
class SecureConnectionResolver implements TenantConnectionResolverInterface
{
    public function resolveParameters(TenantInterface $tenant): array
    {
        // Ensure tenant can only access their own database
        $allowedDatabases = $this->getAllowedDatabases($tenant);
        $requestedDatabase = $this->getDatabaseName($tenant);
        
        if (!in_array($requestedDatabase, $allowedDatabases)) {
            throw new SecurityException('Tenant not authorized for database');
        }
        
        return $this->buildConnectionParameters($tenant);
    }
}
```