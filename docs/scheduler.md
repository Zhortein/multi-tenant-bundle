# Symfony Scheduler and tenant-safe redispatch

Symfony Scheduler exposes due recurring messages through a Messenger transport.
When scheduled work must run later on a persistent transport, schedule Symfony's
`RedispatchMessage` control message instead of scheduling the application
message directly.

> **Navigation:** [Messenger](messenger.md) · [Persistent lifecycle](persistent-lifecycle.md) · [Documentation index](index.md)

## Why `RedispatchMessage` is required

The envelope delivered by `SchedulerTransport` has a `ReceivedStamp`. Symfony's
`SendMessageMiddleware` does not apply outgoing routing to a received envelope.
If the recurring item is the application message itself, its handler can
therefore execute synchronously inside the Scheduler Worker.

For a `RedispatchMessage`, Symfony's public handler dispatches the encapsulated
message again with its explicit destination. That new dispatch can cross a
persistent transport before an application Worker invokes the business
handler. The bundle preserves the destination and Scheduler stamps; Symfony's
sender locator remains responsible for resolving and validating the transport.

## Installation and transport

Install Scheduler and the adapter for the persistent transport you use. This
Doctrine example works on every supported Symfony branch:

```bash
composer require symfony/scheduler symfony/doctrine-messenger
```

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            scheduled_async:
                dsn: 'doctrine://default'
                options:
                    queue_name: scheduled_async
```

Do not use `sync://` for `scheduled_async`: the explicit destination is intended
to establish a persistent boundary between the Scheduler Worker and the
application Worker. PostgreSQL >= 16 is supported for the Doctrine transport;
the bundle's PostgreSQL 16/18 tests use no PostgreSQL 18-only feature.

## Classified recurring messages

Application messages still implement exactly one bundle marker:

```php
<?php

namespace App\Message;

use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

final readonly class RefreshPublicCatalog implements GlobalMessageInterface
{
    public function __construct(public string $locale)
    {
    }
}

final readonly class RebuildTenantIndex implements TenantAwareMessageInterface
{
    public function __construct(public string $tenantId)
    {
    }
}
```

For global work, redispatch the classified message directly. For tenant work,
put the application message and its tenant ID in an `Envelope`; the Scheduler
process has no request from which the bundle could infer a tenant.

```php
<?php

namespace App\Scheduler;

use App\Message\RebuildTenantIndex;
use App\Message\RefreshPublicCatalog;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

#[AsSchedule('tenant_safe')]
final class TenantSafeScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every(
                '15 minutes',
                new RedispatchMessage(
                    new RefreshPublicCatalog('fr_FR'),
                    'scheduled_async',
                ),
            ))
            ->add(RecurringMessage::every(
                '1 hour',
                new RedispatchMessage(
                    new Envelope(
                        new RebuildTenantIndex('42'),
                        [new TenantStamp('42')],
                    ),
                    'scheduled_async',
                ),
            ));
    }
}
```

Run the two independently managed Workers:

```bash
php bin/console messenger:consume scheduler_tenant_safe
php bin/console messenger:consume scheduled_async
```

The first command may generate and persist due occurrences, but it must never
run `RefreshPublicCatalog` or `RebuildTenantIndex` handlers. The second command
deserializes the application envelope, runs the same receive-side tenant/global
validation again, and only then calls the handler.

## Fail-closed wrapper contract

The bundle recognizes only Symfony's final `RedispatchMessage` control class;
this is not a generic vendor-message allowlist and there is no public scope
resolver extension point. It recursively inspects the public encapsulated
message/envelope API, with a maximum of eight redispatch levels, and requires:

- a readable encapsulated object and at least one non-empty destination;
- exactly one tenant/global classification on the final application message;
- no `TenantStamp` on global work;
- at least one mutually consistent `TenantStamp` for received tenant work;
- consistency with the active tenant while sending and a registry-known tenant
  while consuming.

All relevant outer and inner `TenantStamp` instances are checked. Empty,
unreadable, cyclic, repeated, excessively deep, unclassified, doubly
classified, contradictory, or already-received internal envelopes are rejected
before redispatch. The last case prevents an internal `ReceivedStamp` from
silently suppressing the requested persistent send. An
artificial `ScheduledStamp` grants no trust, and every other unrecognized
technical message remains fail-closed. Serialization failures and unknown
transport names remain explicit failures; they never count as successful work.

`ScheduledStamp`, locale stamps, and other serializable application metadata
remain attached through Symfony's redispatch. Applications remain responsible
for choosing a real persistent transport, keeping messages and useful stamps
serializable, configuring retry/DLQ policy, and avoiding sensitive metadata.

## Routing strategies

The Scheduler recipe is valid with both bundle strategies:

- `tenant_transport` retains the historical map/default behavior for ordinary
  tenant dispatches. The explicit redispatch destination remains authoritative.
- `symfony_routing` never adds a bundle `TransportNamesStamp` and never falls
  back to `default_transport`. Symfony applies the destination carried by
  `RedispatchMessage` through its normal sender locator.

The bundle does not duplicate `SendersLocator`, interpret
`framework.messenger.routing`, or disable any tenant, Doctrine, RLS, or
lifecycle middleware.

## Other persistent loops

For work executed directly by a custom long-running loop rather than via
Messenger redispatch, use `TenantExecutionBoundaryInterface`. Each callback
starts and ends at `NONE`, after success or exception:

```php
use Zhortein\MultiTenantBundle\Lifecycle\TenantExecutionBoundaryInterface;

$boundary->run(function (): void {
    // Resolve and set one tenant explicitly, or perform classified global work.
});
```

## RC10 application middleware coexistence

Application `validation` and custom middleware remain installed alongside the
bundle's automatic guards. No Scheduler or routing configuration changes are
required for this composition fix. The context established for a received
`RedispatchMessage` encloses Symfony's redispatch handler and its nested send;
cleanup happens after the whole chain, including failures.

The Consumer App records real Validator callbacks and application middleware
at three dispatches: the Scheduler's technical wrapper, the outgoing
application message before Doctrine persistence, and the deserialized
application message in the separate Worker. Each configured validator and
middleware runs once per dispatch. Symfony does not automatically validate the
inner payload merely by validating a `RedispatchMessage` wrapper; the nested
application dispatch performs that payload validation normally.

The persistent proof checks that the Scheduler Worker never invokes the
business handler, and that the application Worker clears context after either
success or an application exception. See [Messenger composition](messenger.md)
and the [RC9 migration](migration-rc9-to-rc10.md).
