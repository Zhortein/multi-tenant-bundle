# Tenant Context

The tenant context is the core mechanism that manages the current tenant state throughout your application. It provides a centralized way to access the current tenant and ensures that all tenant-aware services operate within the correct tenant scope.

## How It Works

The tenant context system consists of several components working together:

1. **Tenant Resolution**: Determines which tenant is active based on the HTTP request
2. **Context Storage**: Maintains the current tenant state during request processing
3. **Service Integration**: Provides tenant information to all tenant-aware services

## TenantContextInterface

The main interface for accessing tenant context:

```php
<?php

namespace Zhortein\MultiTenantBundle\Context;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;

interface TenantContextInterface
{
    /**
     * Gets the current tenant.
     */
    public function getTenant(): ?TenantInterface;

    /**
     * Sets the current tenant.
     */
    public function setTenant(?TenantInterface $tenant): void;

    /**
     * Checks if a tenant is currently set.
     */
    public function hasTenant(): bool;

    /**
     * Clears the current tenant context.
     */
    public function clear(): void;
}
```

## Using Tenant Context in Controllers

### Basic Usage

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class DashboardController extends AbstractController
{
    public function index(TenantContextInterface $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            throw $this->createNotFoundException('No tenant found');
        }
        
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $tenant,
            'tenantName' => $tenant->getName(),
            'tenantSlug' => $tenant->getSlug(),
        ]);
    }
}
```

### Conditional Logic Based on Tenant

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class FeatureController extends AbstractController
{
    public function premiumFeature(TenantContextInterface $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant context');
        }
        
        // Check if tenant has premium features
        if (!$this->isPremiumTenant($tenant)) {
            throw $this->createAccessDeniedException('Premium feature not available');
        }
        
        return $this->render('features/premium.html.twig');
    }
    
    private function isPremiumTenant(TenantInterface $tenant): bool
    {
        // Your business logic here
        return $tenant->getSlug() === 'premium-tenant';
    }
}
```

## Using Tenant Context in Services

### Service with Tenant Context Dependency

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class ReportService
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantSettingsManager $settingsManager,
    ) {}

    public function generateReport(): array
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }
        
        // Get tenant-specific settings
        $reportFormat = $this->settingsManager->get('report_format', 'pdf');
        $includeCharts = $this->settingsManager->get('report_include_charts', true);
        
        return [
            'tenant' => $tenant->getSlug(),
            'format' => $reportFormat,
            'include_charts' => $includeCharts,
            'generated_at' => new \DateTimeImmutable(),
        ];
    }
}
```

### Event Subscriber Using Tenant Context

```php
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Psr\Log\LoggerInterface;

class TenantLoggingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10], // After tenant resolution
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenant = $this->tenantContext->getTenant();
        
        if ($tenant) {
            $this->logger->info('Request processed for tenant', [
                'tenant_slug' => $tenant->getSlug(),
                'tenant_name' => $tenant->getName(),
                'request_uri' => $event->getRequest()->getRequestUri(),
            ]);
        }
    }
}
```

## Tenant Context Lifecycle

### 1. Request Processing

```
HTTP Request → Tenant Resolver → Tenant Context → Application Logic
```

1. **HTTP Request**: Incoming request with tenant information
2. **Tenant Resolver**: Extracts tenant identifier from request
3. **Tenant Context**: Stores resolved tenant for request duration
4. **Application Logic**: Uses tenant context throughout request

### 2. Context Scoping

The tenant context is scoped to the current request and is automatically:

- **Set** during request processing by the `TenantRequestListener`
- **Available** throughout the request lifecycle
- **Cleared** after response is sent (in some configurations)

### 3. Thread Safety

The tenant context is designed to be thread-safe within the context of a single HTTP request. Each request gets its own tenant context instance.

## Advanced Usage

### Manual Tenant Switching

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

class CrossTenantService
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantRegistryInterface $tenantRegistry,
    ) {}

    public function processAllTenants(): void
    {
        $originalTenant = $this->tenantContext->getTenant();
        
        try {
            foreach ($this->tenantRegistry->getAll() as $tenant) {
                // Switch to each tenant
                $this->tenantContext->setTenant($tenant);
                
                // Process tenant-specific logic
                $this->processTenant($tenant);
            }
        } finally {
            // Restore original tenant context
            $this->tenantContext->setTenant($originalTenant);
        }
    }
    
    private function processTenant(TenantInterface $tenant): void
    {
        // Your tenant-specific processing logic
        echo "Processing tenant: " . $tenant->getSlug() . "\n";
    }
}
```

### Tenant Context in Commands

```php
<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

#[AsCommand(name: 'app:tenant-report')]
class TenantReportCommand extends Command
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantRegistryInterface $tenantRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantSlug = $input->getOption('tenant');
        
        if ($tenantSlug) {
            $tenant = $this->tenantRegistry->getBySlug($tenantSlug);
            $this->tenantContext->setTenant($tenant);
            
            $output->writeln("Processing tenant: {$tenant->getName()}");
            // Your tenant-specific logic here
        } else {
            $output->writeln('No tenant specified');
        }
        
        return Command::SUCCESS;
    }
}
```

## Configuration

The tenant context behavior can be configured in your bundle configuration:

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    # Tenant entity class
    tenant_entity: 'App\Entity\Tenant'
    
    # Require tenant for all requests
    require_tenant: false
    
    # Default tenant slug (optional)
    default_tenant: null
```

## Error Handling

### No Tenant Found

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;

class BaseController extends AbstractController
{
    protected function requireTenant(TenantContextInterface $tenantContext): TenantInterface
    {
        $tenant = $tenantContext->getTenant();
        
        if (!$tenant) {
            throw new TenantNotFoundException('No tenant found in current context');
        }
        
        return $tenant;
    }
}
```

### Tenant Validation

```php
<?php

namespace App\Service;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

class TenantValidationService
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {}

    public function validateCurrentTenant(): bool
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant) {
            return false;
        }
        
        // Add your validation logic
        return $tenant->isActive() && $tenant->getExpiresAt() > new \DateTimeImmutable();
    }
}
```

## Best Practices

### 1. Always Check for Tenant Existence

```php
// Good
$tenant = $tenantContext->getTenant();
if (!$tenant) {
    throw new \RuntimeException('No tenant context');
}

// Bad - can cause null pointer exceptions
$tenantName = $tenantContext->getTenant()->getName();
```

### 2. Use Dependency Injection

```php
// Good - inject the interface
public function __construct(
    private TenantContextInterface $tenantContext,
) {}

// Bad - access via service locator
$tenantContext = $this->container->get('tenant.context');
```

### 3. Handle Context Switching Carefully

```php
// Good - always restore original context
$originalTenant = $this->tenantContext->getTenant();
try {
    $this->tenantContext->setTenant($newTenant);
    // Do work
} finally {
    $this->tenantContext->setTenant($originalTenant);
}
```

### 4. Use Type Hints

```php
// Good - explicit type hints
public function processTenant(TenantInterface $tenant): void
{
    // Implementation
}

// Bad - no type safety
public function processTenant($tenant): void
{
    // Implementation
}
```

## Integration with Other Components

The tenant context integrates seamlessly with other bundle components:

- **[Doctrine Filter](doctrine-tenant-filter.md)**: Automatically filters queries based on current tenant
- **[Tenant Settings](tenant-settings.md)**: Provides tenant-specific configuration
- **[Mailer](mailer.md)**: Uses tenant context for email configuration
- **[Messenger](messenger.md)**: Routes messages based on tenant context
- **[Storage](storage.md)**: Isolates files by tenant

## Troubleshooting

### Common Issues

1. **Tenant Context Not Available**: Ensure the `TenantRequestListener` is properly configured
2. **Wrong Tenant Resolved**: Check your tenant resolver configuration
3. **Context Lost in Async Operations**: Manually set tenant context in background jobs

### Debug Information

```php
// Check if tenant context is available
if ($tenantContext->hasTenant()) {
    $tenant = $tenantContext->getTenant();
    echo "Current tenant: " . $tenant->getSlug();
} else {
    echo "No tenant context available";
}
```