# PostgreSQL Row-Level Security (RLS) Implementation Summary

This document summarizes the PostgreSQL Row-Level Security (RLS) implementation added to the multi-tenant bundle as a defense-in-depth security measure.

> 📖 **Navigation**: [← Project Overview](project-overview.md) | [Back to Documentation Index](index.md) | [Examples →](examples/)

## Overview

The RLS feature provides database-level tenant isolation by creating PostgreSQL policies that automatically filter rows based on the current tenant context. This works alongside Doctrine filters to provide multiple layers of protection.

## Components Implemented

### 1. TenantSessionConfigurator Service

**Location**: `src/Database/TenantSessionConfigurator.php`

**Features**:
- HTTP Kernel Request listener (high priority) that sets PostgreSQL session variables
- Messenger middleware for worker processes
- Automatic session configuration and cleanup
- PostgreSQL platform detection
- Configurable session variable name

**Key Methods**:
- `onKernelRequest()`: Sets session variable from tenant context during HTTP requests
- `handle()`: Messenger middleware that restores tenant context for workers
- `configureTenantSession()`: Sets `app.tenant_id` session variable
- `clearTenantSession()`: Cleans up session variable

### 2. SyncRlsPoliciesCommand Console Command

**Location**: `src/Command/SyncRlsPoliciesCommand.php`

**Command**: `tenant:rls:sync`

**Features**:
- Scans Doctrine metadata for `#[AsTenantAware]` entities
- Generates SQL to enable RLS and create policies
- Idempotent operation (safe to run multiple times)
- Preview mode (default) and apply mode (`--apply`)
- Force recreation of existing policies (`--force`)
- PostgreSQL-only operation with platform detection

**Generated SQL Example**:
```sql
ALTER TABLE products ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_products ON products
    USING (tenant_id::text = current_setting('app.tenant_id', true));
```

### 3. Configuration Integration

**Bundle Configuration**:
```yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db'
        rls:
            enabled: true
            session_variable: 'app.tenant_id'
            policy_name_prefix: 'tenant_isolation'
```

**Service Registration**: Automatic registration in `ZhorteinMultiTenantExtension.php`

### 4. Messenger Integration

**TenantStamp**: Enhanced to work with RLS session configuration
**Middleware**: Automatically restores tenant session variables in worker processes

## Tests Implemented

### Unit Tests

1. **TenantSessionConfiguratorTest** (11 tests)
   - HTTP request listener functionality
   - Messenger middleware behavior
   - Session configuration and cleanup
   - Error handling and edge cases

2. **SyncRlsPoliciesCommandTest** (7 tests)
   - SQL generation for different scenarios
   - Command execution with various options
   - PostgreSQL platform detection
   - Idempotent behavior testing

### Integration Tests

1. **SyncRlsPoliciesCommandIntegrationTest** (3 tests)
   - Command instantiation and registration
   - Non-PostgreSQL database handling
   - Command metadata validation

### Functional Tests

1. **RlsIntegrationTest** (4 tests, skipped if no PostgreSQL)
   - Cross-tenant data access prevention
   - Insert validation with RLS policies
   - Behavior without tenant context
   - Defense-in-depth verification

## Security Benefits

1. **Defense-in-Depth**: Even if Doctrine filters are disabled or bypassed, RLS policies still protect data
2. **Database-Level Enforcement**: Protection is enforced at the PostgreSQL level, not just the application level
3. **Automatic Filtering**: No need to manually add tenant conditions to queries
4. **Cross-Tenant Protection**: Prevents accidental data leakage between tenants

## Usage Examples

### Enable RLS in Configuration
```yaml
zhortein_multi_tenant:
    database:
        strategy: 'shared_db'
        rls:
            enabled: true
```

### Sync RLS Policies
```bash
# Preview SQL that will be generated
php bin/console tenant:rls:sync

# Apply policies to database
php bin/console tenant:rls:sync --apply

# Force recreation of existing policies
php bin/console tenant:rls:sync --apply --force
```

### Entity Requirements
```php
#[ORM\Entity]
#[AsTenantAware] // Required for RLS policy generation
class Product
{
    use TenantAwareEntityTrait; // Adds tenant_id field
    // ... entity fields
}
```

## Limitations and Considerations

1. **PostgreSQL Only**: RLS is a PostgreSQL-specific feature
2. **Shared Database Only**: Only works with `shared_db` strategy
3. **Performance Impact**: RLS policies add overhead to queries
4. **Session Management**: Requires proper tenant context management

## Files Added/Modified

### New Files
- `src/Database/TenantSessionConfigurator.php`
- `src/Command/SyncRlsPoliciesCommand.php`
- `tests/Unit/Database/TenantSessionConfiguratorTest.php`
- `tests/Unit/Command/SyncRlsPoliciesCommandTest.php`
- `tests/Integration/Command/SyncRlsPoliciesCommandIntegrationTest.php`
- `tests/Functional/Database/RlsIntegrationTest.php`
- `docs/rls-security.md`
- `docs/examples/rls-configuration.yaml`

### Modified Files
- `src/DependencyInjection/ZhorteinMultiTenantExtension.php`
- `src/Registry/TenantRegistryInterface.php` (added `findBySlug` method)
- `src/Registry/DoctrineTenantRegistry.php` (implemented `findBySlug`)
- `src/Registry/InMemoryTenantRegistry.php` (implemented `findBySlug`)
- `docs/database-strategies.md` (added RLS documentation)

## Testing Coverage

- **Unit Tests**: 142 tests, 338 assertions
- **All Tests Pass**: ✅
- **Functional Tests**: Properly skip when PostgreSQL unavailable
- **Integration Tests**: Verify command registration and behavior

The implementation provides a robust, well-tested PostgreSQL RLS integration that enhances the security of the multi-tenant bundle while maintaining backward compatibility and ease of use.