# PostgreSQL Row-Level Security (RLS)

The multi-tenant bundle supports PostgreSQL Row-Level Security (RLS) as an additional layer of defense-in-depth protection when using the `shared_db` database strategy.

> 📖 **Navigation**: [← Migrations](migrations.md) | [Back to Documentation Index](index.md) | [Decorators →](decorators.md)

## Overview

Row-Level Security provides database-level tenant isolation by creating policies that automatically filter rows based on the current tenant context. This works alongside Doctrine filters to provide multiple layers of protection.

## Threat model and query boundaries

RLS can protect tenant-owned rows when application code reaches PostgreSQL through Doctrine ORM, DQL, QueryBuilder, DBAL, or native SQL. It is optional defense in depth, not a prerequisite for safe bundle behavior and not a replacement for application authorization. The bundle's Doctrine filter protects ORM-generated reads only; tenant-aware repositories and write guards provide additional application boundaries. DBAL, native SQL, DQL bulk operations, migrations, and disabled listeners remain outside those ORM guarantees.

The supported policy is fail-closed. With no active tenant, `app.tenant_id` is cleared to an empty value and tenant-owned SELECT, INSERT, UPDATE, and DELETE operations expose or affect no rows. Same-tenant operations are allowed. Cross-tenant SELECT rows are hidden, cross-tenant UPDATE and DELETE affect zero rows, and cross-tenant INSERT is rejected by `WITH CHECK`.

RLS does not protect:

- tables that are not tenant-aware or do not have a synchronized policy;
- privileged maintenance connections that deliberately use a superuser or a role with `BYPASSRLS`;
- table owners unless `FORCE ROW LEVEL SECURITY` is enabled;
- data copied outside PostgreSQL before the policy is evaluated.

Application authorization remains required. RLS isolates tenant rows; it does not decide whether a user may perform an operation inside the current tenant.

## Required PostgreSQL roles

Use separate administrative and application roles:

- the migration role owns or alters tables, enables and forces RLS, creates policies, and grants the minimum required privileges;
- the application role must be `NOSUPERUSER`, `NOBYPASSRLS`, and normally must not own tenant tables;
- background workers and CLI commands that access tenant data must use the same restricted application role.

Never validate isolation as `POSTGRES_USER`, a superuser, or a `BYPASSRLS` role. Those roles bypass policies by design. `FORCE ROW LEVEL SECURITY` additionally subjects table owners to policies, but it does not restrict superusers or `BYPASSRLS`.


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
   SELECT set_config('app.tenant_id', '123', false);
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
ALTER TABLE products FORCE ROW LEVEL SECURITY;

-- Apply the same tenant predicate to reads and writes
CREATE POLICY tenant_isolation_products ON products
    FOR ALL
    USING (tenant_id::text = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id::text = current_setting('app.tenant_id', true));
```

## Session lifecycle, transactions, and pooling

The bundle writes the tenant identifier with session scope so it survives transaction commit or rollback. Every request, message, command iteration, or manually managed tenant operation must set the context before the first tenant-owned query and clear it in a `finally` path. Clearing the PHP tenant context alone is not enough: invoke the session configurator so the PostgreSQL setting is cleared before the connection returns to a pool or handles another tenant.

A newly opened physical connection starts without tenant state and therefore fails closed. Persistent connections and external poolers can reuse physical sessions; configure pool reset behavior and still clear the setting explicitly. Transaction-pooling modes require particular care because session variables may not follow the logical client. Use a pooling mode that preserves the session for the complete tenant operation, or set and verify the tenant setting at the transaction boundary.

The mandatory PostgreSQL suite exercises tenant A/B switching, rollback cleanup, connection reuse, and a newly opened connection. It uses raw DBAL SQL so the proof is independent from the Doctrine filter.

## Testing RLS Protection

RLS tests deliberately bypass the Doctrine filter inside test infrastructure to prove the independent database layer. Application code must not copy this bypass; use `GlobalDoctrineScopeInterface` for explicitly authorized global ORM work.

## Limitations

- **PostgreSQL only**: RLS is a PostgreSQL-specific feature
- **Shared database only**: Only works with `shared_db` strategy
- **Performance impact**: RLS policies add overhead to queries
- **Complex queries**: May need manual policy adjustments for complex scenarios
- **Partitioning and pooling**: Require deployment-specific policy and session-reset validation
- **Privileged maintenance**: Must use a separate audited path because privileged roles can bypass RLS

## Troubleshooting

### Diagnostic checklist

Run these checks with the same database role and connection path used by the application:

```sql
SELECT current_user,
       current_setting('app.tenant_id', true) AS tenant_id,
       rolsuper,
       rolbypassrls
FROM pg_roles
WHERE rolname = current_user;

SELECT relrowsecurity, relforcerowsecurity
FROM pg_class
WHERE oid = 'products'::regclass;

SELECT policyname, cmd, qual, with_check
FROM pg_policies
WHERE schemaname = 'public' AND tablename = 'products';
```

Expected application-role values are `rolsuper = false`, `rolbypassrls = false`, and both table flags set to `true`. The policy must include both `qual` and `with_check`.

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

Enabling RLS changes database authorization behavior. Treat it as a security migration: verify role ownership, existing policies, every tenant-owned table, connection pooling, and the mandatory PostgreSQL test suite before production rollout.
