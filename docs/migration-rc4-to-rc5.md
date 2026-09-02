# Migrating from RC4 to RC5

RC5 changes lifecycle behavior for persistent Symfony kernels, FrankenPHP
workers, Messenger workers, reused Console applications, and Scheduler loops.
No application-level workaround is required, but applications with custom
HTTP identity-based resolution must adopt the explicit late-resolution path.

## Required review

- `TenantContextInterface` now extends Symfony's `ResetInterface`; custom
  implementations must provide an idempotent `reset()` that synchronizes all
  derived state to `NONE`.
- `listeners.request_listener: false` disables only automatic resolution. The
  main-request lifecycle barrier remains enabled and clears stale state.
- Automatic resolution still runs early and is appropriate for host, path,
  header, DNS, or platform configuration strategies.
- Session, authenticated-user, membership, and authorization resolvers must
  disable automatic resolution and call
  `TenantRequestContextLoaderInterface::load($request)` from application code
  after the relevant firewall/authentication work.
- A resolver returning `null` or throwing leaves the context at `NONE`. RC4's
  accidental retention of the previous tenant is removed.
- Messenger and Console boundaries never restore a tenant that existed before
  the received message or command.
- `TenantAwareCacheAdapterDecorator` now implements a valid, idempotent
  `reset()` method. Symfony may safely include an initialized `cache.app` in
  `services_resetter`.

## Late resolution example

```yaml
zhortein_multi_tenant:
    listeners:
        request_listener: false
```

```php
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Zhortein\MultiTenantBundle\Http\TenantRequestContextLoaderInterface;

final readonly class PostAuthenticationTenantListener
{
    public function __construct(private TenantRequestContextLoaderInterface $loader)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->loader->load($event->getRequest());
        }
    }
}
```

The bundle does not depend on SecurityBundle. The resolver supplied by the
application may use its own session, token, membership, or authorization
services. Do not rely on a universal event priority: lazy and multiple
firewalls require application-specific placement.

## Reset failure and dirty Doctrine managers

RC5 never flushes during reset. A dirty UnitOfWork, an RLS cleanup error, or an
unrestorable route invalidates the logical tenant, closes/quarantines unsafe
Doctrine resources, and raises a non-sensitive public exception. If the
controller, handler, command, or scheduled callback already failed, its
exception remains the primary cause and cleanup is recorded separately.

See [Persistent Process Lifecycle](persistent-lifecycle.md) for the complete
state inventory, event sequence, streamed-response behavior, and reset order.
