# Scheduler and Custom Persistent Loops

Run each scheduled or custom long-lived operation through the public
`TenantExecutionBoundaryInterface`:

```php
use Zhortein\MultiTenantBundle\Lifecycle\TenantExecutionBoundaryInterface;

$boundary->run(function (): void {
    // Resolve and set a tenant explicitly, or perform classified global work.
});
```

The callback starts at `NONE` and ends at `NONE` after success or exception.
The boundary never restores process-local state that existed before the
callback. If application work and cleanup both fail, the application failure
is retained as the primary cause and the cleanup failure is available
separately. This API is appropriate for Symfony Scheduler handlers when several
tasks can execute in one process.
