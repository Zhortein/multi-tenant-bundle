# CLI Commands

The multi-tenant bundle provides comprehensive console commands for managing tenants, databases, migrations, fixtures, and settings.

## Tenant Management

### List Tenants
```bash
# List all tenants
php bin/console tenant:list

# List active tenants only
php bin/console tenant:list --active

# Show detailed information
php bin/console tenant:list --verbose
```

### Create Tenant
```bash
# Interactive tenant creation
php bin/console tenant:create

# Create tenant with parameters
php bin/console tenant:create --slug=acme --name="ACME Corp" --domain=acme.example.com
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

# Dry run migrations
php bin/console tenant:migrate --dry-run

# Migration status
php bin/console tenant:migrations:status
```

### Fixtures
```bash
# Load fixtures for all tenants
php bin/console tenant:fixtures:load

# Load fixtures for specific tenant
php bin/console tenant:fixtures:load --tenant=acme

# Load specific fixture groups
php bin/console tenant:fixtures:load --group=dev --group=test
```

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