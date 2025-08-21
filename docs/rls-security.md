# PostgreSQL Row-Level Security (RLS)

The multi-tenant bundle supports PostgreSQL Row-Level Security (RLS) as an additional layer of defense-in-depth protection when using the `shared_db` database strategy.

> 📖 **Navigation**: [← Migrations](migrations.md) | [Back to Documentation Index](index.md) | [Decorators →](decorators.md)

## Overview

Row-Level Security provides database-level tenant isolation by creating policies that automatically filter rows based on the current tenant context. This works alongside Doctrine filters to provide multiple layers of protection.

## Benefits

- **Defense-in-depth**: Even if Doctrine filters are disabled or bypassed, RLS policies still protect data
- **Database-level enforcement**: Protection is enforced at the PostgreSQL level, not just the application level
- **Automatic filtering**: No need to manually add tenant conditions to queries
- **Cross-tenant protection**: Prevents accidental data leakage between tenants

## Configuration

Enable RLS in your bundle configuration:

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db'
        enable_filter: true
        rls:
            enabled: true
            session_variable: 'app.tenant_id'  # PostgreSQL session variable name
            policy_name_prefix: 'tenant_isolation'  # Prefix for RLS policy names
```

## Setup

### 1. Sync RLS Policies

Generate and apply RLS policies for your tenant-aware entities:

```bash
# Preview the SQL that will be generated
php bin/console tenant:rls:sync

# Apply the policies to the database
php bin/console tenant:rls:sync --apply

# Force recreation of existing policies
php bin/console tenant:rls:sync --apply --force
```

### 2. Entity Requirements

Entities must be marked with the `#[AsTenantAware]` attribute and include a `tenant_id` field:

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Doctrine\TenantAwareEntityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[AsTenantAware] // Required for RLS policy generation
class Product
{
    use TenantAwareEntityTrait; // Adds tenant relationship and tenant_id field

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // ... other fields
}
```

## How It Works

### HTTP Requests

1. The `TenantRequestListener` resolves the tenant from the request
2. The `TenantSessionConfigurator` sets the PostgreSQL session variable:
   ```sql
   SELECT set_config('app.tenant_id', '123', true);
   ```
3. RLS policies automatically filter queries based on this session variable

### Messenger Workers

1. Messages include a `TenantStamp` with tenant information
2. The `TenantSessionConfigurator` middleware restores the tenant context
3. The session variable is set for the worker process
4. After message processing, the context is cleared

### Generated Policies

For each tenant-aware table, the command generates:

```sql
-- Enable RLS on the table
ALTER TABLE products ENABLE ROW LEVEL SECURITY;

-- Create the isolation policy
CREATE POLICY tenant_isolation_products ON products
    USING (tenant_id::text = current_setting('app.tenant_id', true));
```

## Testing RLS Protection

You can verify that RLS is working by temporarily disabling Doctrine filters:

```php
// Disable Doctrine tenant filter
$filters = $entityManager->getFilters();
if ($filters->has('tenant_filter')) {
    $filters->disable('tenant_filter');
}

// This query should still be filtered by RLS
$products = $entityManager->getRepository(Product::class)->findAll();
// Will only return products for the current tenant due to RLS
```

## Limitations

- **PostgreSQL only**: RLS is a PostgreSQL-specific feature
- **Shared database only**: Only works with `shared_db` strategy
- **Performance impact**: RLS policies add overhead to queries
- **Complex queries**: May need manual policy adjustments for complex scenarios

## Troubleshooting

### No Data Returned

If queries return no data after enabling RLS:

1. Check that the session variable is set:
   ```sql
   SELECT current_setting('app.tenant_id', true);
   ```

2. Verify the policy exists:
   ```sql
   SELECT * FROM pg_policies WHERE tablename = 'your_table';
   ```

3. Check that tenant context is properly set in your application

### Policy Conflicts

If you have existing RLS policies, use the `--force` option to recreate them:

```bash
php bin/console tenant:rls:sync --apply --force
```

### Performance Issues

RLS policies can impact query performance. Consider:

- Adding appropriate indexes on `tenant_id` columns
- Monitoring query execution plans
- Adjusting policies for specific use cases

## Security Considerations

- RLS provides defense-in-depth but should not be the only security measure
- Always use HTTPS to protect session data in transit
- Regularly audit RLS policies and tenant access patterns
- Consider using separate database users for different tenant tiers

## Migration

When migrating from non-RLS to RLS setup:

1. Enable RLS configuration
2. Run the sync command to create policies
3. Test thoroughly in a staging environment
4. Monitor performance after deployment

The RLS feature is designed to be non-breaking and can be enabled on existing installations without data migration.