# CLI Commands

The multi-tenant bundle provides comprehensive console commands for managing tenants, databases, migrations, fixtures, and settings. All tenant-aware commands support global tenant context options.

## Global Tenant Context Options

All tenant-aware commands support these global options for specifying tenant context:

### `--tenant` Option
Specifies the tenant to operate on by slug or ID:
```bash
php bin/console tenant:list --tenant=acme
php bin/console tenant:migrate --tenant=demo
php bin/console tenant:fixtures --tenant=123
```

### Environment Variable Support
Commands also support the `TENANT_ID` environment variable:
```bash
# Set via environment
export TENANT_ID=acme
php bin/console tenant:list

# Set inline
TENANT_ID=acme php bin/console tenant:migrate

# Available in both $_ENV and $_SERVER
```

### Priority Resolution Order
1. `--tenant` command option (highest priority)
2. `TENANT_ID` environment variable
3. `TENANT_ID` server variable
4. No tenant context (operates on all tenants)

## Tenant Management

### List Tenants
```bash
# List all tenants (table format)
php bin/console tenant:list

# List specific tenant
php bin/console tenant:list --tenant=acme

# Using environment variable
TENANT_ID=acme php bin/console tenant:list

# Show detailed information (includes mailer/messenger DSNs)
php bin/console tenant:list --detailed

# JSON output format
php bin/console tenant:list --format=json

# JSON with detailed information
php bin/console tenant:list --format=json --detailed

# YAML output format
php bin/console tenant:list --format=yaml --detailed
```

**Example Output (Table format):**
```
 ---- --------- 
  ID   Slug     
 ---- --------- 
  1    acme     
  2    demo     
 ---- --------- 

 [OK] Found 2 tenant(s).
```

**Example Output (JSON format):**
```json
[
    {
        "id": "1",
        "slug": "acme"
    },
    {
        "id": "2", 
        "slug": "demo"
    }
]
```

### Create Tenant
```bash
# Interactive tenant creation
php bin/console tenant:create

# Create tenant with parameters
php bin/console tenant:create --slug=acme --name="ACME Corp" --domain=acme.example.com
```

### Impersonate Tenant (Admin Only)

**⚠️ SECURITY WARNING**: This command allows impersonating tenants and should only be used by administrators in development/debugging scenarios.

```bash
# Impersonate tenant by slug
php bin/console tenant:impersonate acme

# Impersonate tenant by ID
php bin/console tenant:impersonate 1

# Show tenant configuration (with sensitive data masking)
php bin/console tenant:impersonate acme --show-config

# Execute command in tenant context
php bin/console tenant:impersonate acme --command="doctrine:schema:validate"

# Interactive mode for multiple operations
php bin/console tenant:impersonate acme --interactive
```

**Security Features:**
- Only enabled in debug mode by default
- Can be disabled via configuration
- Shows security warnings
- Masks sensitive data in output (DSN passwords)
- Configurable via `allow_impersonation` setting

**Example Configuration Display:**
```
 =================== TENANT CONFIGURATION =================== 
  ID: 1
  Slug: acme
  Mailer DSN: smtp://user:***@localhost:587
  Messenger DSN: redis://user:***@localhost:6379
 ============================================================== 
```

## Database Management

### Schema Operations
```bash
# Create schema for all tenants
php bin/console tenant:schema:create

# Create schema for specific tenant
php bin/console tenant:schema:create --tenant=acme

# Drop schema (with confirmation)
php bin/console tenant:schema:drop

# Drop schema with force
php bin/console tenant:schema:drop --force --tenant=acme

# Show SQL without executing
php bin/console tenant:schema:create --dump-sql
```

### Migrations
```bash
# Run migrations for all tenants
php bin/console tenant:migrate

# Run migrations for specific tenant
php bin/console tenant:migrate --tenant=acme

# Using environment variable
TENANT_ID=acme php bin/console tenant:migrate

# Dry run migrations
php bin/console tenant:migrate --dry-run

# Dry run for specific tenant
php bin/console tenant:migrate --tenant=acme --dry-run

# Migration status
php bin/console tenant:migrations:status
```

**Database Strategy Support:**
- **shared_db**: Runs migrations once on the shared database
- **multi_db**: Runs migrations on each tenant's separate database

### Fixtures
```bash
# Load fixtures for all tenants
php bin/console tenant:fixtures

# Load fixtures for specific tenant
php bin/console tenant:fixtures --tenant=acme

# Using environment variable
TENANT_ID=acme php bin/console tenant:fixtures

# Load specific fixture groups
php bin/console tenant:fixtures --group=dev --group=test

# Load fixtures for specific tenant with groups
php bin/console tenant:fixtures --tenant=acme --group=demo

# Append fixtures (don't purge existing data)
php bin/console tenant:fixtures --append

# Append fixtures for specific tenant
php bin/console tenant:fixtures --tenant=acme --append
```

**Note:** Requires `doctrine/doctrine-fixtures-bundle` to be installed.

## Settings Management

### View Settings
```bash
# List all settings for tenant
php bin/console tenant:settings:list --tenant=acme

# Show specific settings
php bin/console tenant:settings:show --tenant=acme --key=theme
```

### Modify Settings
```bash
# Set single setting
php bin/console tenant:settings:set --tenant=acme theme dark

# Set multiple settings
php bin/console tenant:settings:set --tenant=acme theme=dark logo_url=/logo.png
```

### Cache Management
```bash
# Clear settings cache for tenant
php bin/console tenant:settings:clear-cache --tenant=acme

# Clear cache for all tenants
php bin/console tenant:settings:clear-cache --all
```

## File Management

### Storage Operations
```bash
# List files for tenant
php bin/console tenant:files:list --tenant=acme

# Get storage statistics
php bin/console tenant:files:stats --tenant=acme

# Clean up old files
php bin/console tenant:files:cleanup --tenant=acme --older-than="30 days"
```

## Testing & Debugging

### Connection Testing
```bash
# Test tenant database connection
php bin/console tenant:connection:test --tenant=acme

# Test all tenant connections
php bin/console tenant:connection:test --all
```

### Validation
```bash
# Validate tenant configuration
php bin/console tenant:validate --tenant=acme

# Validate database schema
php bin/console tenant:schema:validate --tenant=acme
```

## Error Handling

### Unknown Tenant
When specifying a tenant that doesn't exist:
```bash
$ php bin/console tenant:list --tenant=unknown
[ERROR] Unknown tenant: unknown
```

### Missing Tenant Context
For commands that require a tenant context:
```bash
$ php bin/console some:tenant-specific-command
[ERROR] This command requires a tenant context. Use --tenant option or set TENANT_ID environment variable.
```

### Environment Variable Validation
Invalid tenant from environment variable:
```bash
$ TENANT_ID=unknown php bin/console tenant:list
[ERROR] Unknown tenant: unknown
```

## CI/CD Integration Examples

### Deployment Pipeline
```bash
#!/bin/bash
# Deploy script example

# Migrate all tenants
php bin/console tenant:migrate

# Load fixtures for demo tenant
TENANT_ID=demo php bin/console tenant:fixtures --group=demo

# Validate schema for each tenant
for tenant in $(php bin/console tenant:list --format=json | jq -r '.[].slug'); do
    echo "Validating schema for tenant: $tenant"
    php bin/console tenant:impersonate "$tenant" --command="doctrine:schema:validate"
done
```

### Development Workflow
```bash
# Switch to tenant context for debugging
php bin/console tenant:impersonate acme --interactive

# Check tenant configuration
php bin/console tenant:impersonate acme --show-config

# Run tenant-specific operations
TENANT_ID=acme php bin/console app:custom-command

# Load test data for specific tenant
php bin/console tenant:fixtures --tenant=test --group=dev
```

### Automated Testing
```bash
# Test script for CI
#!/bin/bash

# Test all tenant connections
php bin/console tenant:connection:test --all

# Validate all tenant schemas
for tenant in $(php bin/console tenant:list --format=json | jq -r '.[].slug'); do
    php bin/console tenant:schema:validate --tenant="$tenant"
done

# Run migrations in dry-run mode
php bin/console tenant:migrate --dry-run
```

## Best Practices

1. **Use environment variables** for automated scripts and CI/CD pipelines
2. **Use --tenant option** for interactive/manual operations
3. **Always validate** tenant existence before operations
4. **Use --dry-run** for migrations in production environments
5. **Restrict impersonate command** to development environments only
6. **Monitor command execution** in production environments
7. **Use specific tenant context** when possible for better performance
8. **Implement proper error handling** in automation scripts
9. **Use JSON output format** for parsing in scripts
10. **Test commands in staging** before production deployment