## 🧪 Testing multi-tenancy

In your tests, you may inject a static list of tenants via the InMemoryTenantRegistry:

```php
$registry = new InMemoryTenantRegistry([
    new TenantStub('demo'),
    new TenantStub('local'),
]);
```

Then inject it as the TenantRegistryInterface service.


