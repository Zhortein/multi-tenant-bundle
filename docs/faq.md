# Frequently Asked Questions

> 📖 **Navigation**: [← Decorators](decorators.md) | [Back to Documentation Index](index.md) | [Project Overview →](project-overview.md)

## Database Strategies

### Q: When should I use Shared Database vs Multi-Database?

**A:** Choose based on your requirements:

**Shared Database (shared_db):**
- ✅ Simpler to manage and maintain
- ✅ Lower infrastructure costs
- ✅ Easier cross-tenant reporting
- ✅ Good for small to medium scale (< 1000 tenants)
- ❌ Limited scalability
- ❌ Potential security concerns

**Multi-Database (multi_db):**
- ✅ Complete data isolation
- ✅ Better scalability
- ✅ Enhanced security
- ✅ Tenant-specific customizations possible
- ❌ Higher complexity and costs
- ❌ Difficult cross-tenant operations

### Q: Can I migrate from Shared DB to Multi-DB later?

**A:** Yes, but it requires careful planning:
1. Create tenant-specific databases
2. Migrate data from shared database
3. Update entity mappings (remove tenant_id)
4. Update configuration
5. Test thoroughly

### Q: Do I need tenant_id fields in Multi-DB mode?

**A:** No. In multi-database mode, each tenant has its own database, so tenant_id fields are not needed. Use `#[AsTenantAware(requireTenantId: false)]` and `MultiDbTenantAwareTrait`.

## Entity Configuration

### Q: What's the difference between TenantOwnedEntityInterface and #[AsTenantAware]?

**A:** 
- `TenantOwnedEntityInterface` is the legacy approach
- `#[AsTenantAware]` is the modern, recommended approach using PHP attributes
- The attribute provides more flexibility and configuration options

### Q: How do I make an entity tenant-aware?

**A:** Use the `#[AsTenantAware]` attribute and appropriate trait:

```php
// Shared DB mode
#[AsTenantAware]
class Product
{
    use TenantAwareEntityTrait;
}

// Multi-DB mode
#[AsTenantAware(requireTenantId: false)]
class Product
{
    use MultiDbTenantAwareTrait;
}
```

### Q: Can some entities be shared across tenants?

**A:** Yes, simply don't add the `#[AsTenantAware]` attribute or implement the interface. These entities will be accessible to all tenants.

## Tenant Resolution

### Q: Can I use multiple resolution strategies?

**A:** Yes, you can create a custom resolver that tries multiple strategies in order of preference.

### Q: How do I handle requests without tenant information?

**A:** Configure `require_tenant: false` and handle the case where no tenant is resolved in your application logic.

### Q: Can I resolve tenants based on user authentication?

**A:** Yes, create a custom resolver that uses the authenticated user's tenant association.

## Performance

### Q: How do I optimize queries in Shared DB mode?

**A:** 
1. Add proper indexes on tenant_id columns
2. Use composite indexes (tenant_id + other frequently queried columns)
3. Consider database partitioning for large datasets
4. Monitor query performance regularly

### Q: Does the Doctrine filter impact performance?

**A:** The filter adds a WHERE clause to queries, which has minimal impact if proper indexes exist. Always index tenant_id columns.

### Q: How do I handle large tenant datasets?

**A:** 
1. Use pagination for large result sets
2. Implement proper caching strategies
3. Consider database partitioning
4. Use read replicas for reporting

## Configuration

### Q: How do I configure different email settings per tenant?

**A:** Use the tenant settings system:

```php
$settingsManager->set('mailer_dsn', 'smtp://tenant-smtp.example.com:587');
$settingsManager->set('email_from', 'noreply@tenant.com');
```

### Q: Can tenants have different Symfony configurations?

**A:** The bundle supports tenant-specific settings for:
- Mailer configuration
- Messenger transports
- File storage settings
- Custom application settings

### Q: How do I set up tenant-specific domains?

**A:** Configure the subdomain resolver or create a custom domain mapping resolver:

```yaml
zhortein_multi_tenant:
    resolver:
        type: 'subdomain'
        options:
            base_domain: 'example.com'
```

## Development & Testing

### Q: How do I test multi-tenant functionality?

**A:** 
1. Set tenant context in tests
2. Use fixtures for tenant-specific data
3. Test tenant isolation
4. Verify cross-tenant data access is prevented

### Q: How do I debug tenant resolution issues?

**A:** 
1. Check resolver configuration
2. Verify tenant data exists
3. Use debug commands: `php bin/console debug:container tenant.resolver`
4. Enable logging for tenant resolution

### Q: Can I run the application without tenants?

**A:** Set `require_tenant: false` in configuration, but ensure your application logic handles the case where no tenant is available.

## Deployment

### Q: How do I deploy migrations in production?

**A:** 
1. Always backup databases first
2. Test migrations in staging environment
3. Use `--dry-run` to preview changes
4. Run migrations during maintenance windows
5. Monitor migration progress

### Q: How do I handle tenant onboarding?

**A:** 
1. Create tenant record
2. Set up tenant-specific database (multi-db mode)
3. Run schema creation/migrations
4. Load initial fixtures/seed data
5. Configure tenant-specific settings

### Q: What about zero-downtime deployments?

**A:** 
1. Use backward-compatible migrations
2. Deploy application code first
3. Run migrations after deployment
4. Use feature flags for new functionality

## Security

### Q: How secure is tenant data isolation?

**A:** 
- **Shared DB**: Logical isolation via application-level filtering
- **Multi-DB**: Physical isolation with separate databases
- Always validate tenant access in your application logic

### Q: How do I prevent cross-tenant data access?

**A:** 
1. Always check tenant context in controllers
2. Use the automatic Doctrine filtering
3. Validate entity ownership before operations
4. Implement proper access controls

### Q: Can I audit tenant data access?

**A:** Yes, implement audit logging:
1. Log tenant context in requests
2. Track entity access and modifications
3. Monitor cross-tenant access attempts
4. Use Symfony's security events

## Troubleshooting

### Q: Tenant filter not working, what should I check?

**A:** 
1. Verify filter is enabled in Doctrine configuration
2. Check entity implements tenant interface or uses attribute
3. Ensure tenant context is set
4. Verify tenant_id indexes exist

### Q: Getting "No tenant context" errors?

**A:** 
1. Check tenant resolution configuration
2. Verify tenant data exists
3. Ensure resolver is properly configured
4. Check request contains tenant information

### Q: Migrations failing for some tenants?

**A:** 
1. Check database connectivity
2. Verify tenant database exists
3. Check migration dependencies
4. Review migration logs for specific errors

### Q: Performance issues with many tenants?

**A:** 
1. Add proper database indexes
2. Implement caching strategies
3. Consider database partitioning
4. Monitor query performance
5. Consider switching to multi-db strategy

## Best Practices

### Q: What are the recommended naming conventions?

**A:** 
- Tenant slugs: lowercase, hyphen-separated (e.g., 'acme-corp')
- Database names: prefix with 'tenant_' (e.g., 'tenant_acme')
- Settings keys: underscore-separated (e.g., 'email_from')

### Q: How should I structure my application code?

**A:** 
1. Always inject TenantContextInterface in services
2. Check tenant context before operations
3. Use tenant-aware repositories
4. Implement proper error handling
5. Follow Symfony best practices

### Q: What should I monitor in production?

**A:** 
1. Tenant resolution success rates
2. Database query performance
3. Storage usage per tenant
4. Migration execution times
5. Error rates per tenant