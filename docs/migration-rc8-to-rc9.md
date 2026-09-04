# Migrating from RC8 to RC9

RC9 fixes RC8's incompatibility with Symfony Scheduler's persistent redispatch
path. There is no public bundle API or configuration break.

## Who must act

Applications that do not consume Symfony Scheduler through Messenger need no
change. Applications that schedule persistent work must verify that each
recurring item is a Symfony `RedispatchMessage` whose encapsulated application
message implements exactly one of `TenantAwareMessageInterface` or
`GlobalMessageInterface`.

RC8 rejected the outer technical `RedispatchMessage` as unclassified. Do not
work around that rejection by disabling bundle middleware or scheduling the
application message directly. A direct Scheduler delivery has a
`ReceivedStamp`; Symfony then skips outgoing routing and may invoke the
business handler in the Scheduler Worker.

## Required Scheduler shape

Global work carries no `TenantStamp`:

```php
use Symfony\Component\Messenger\Message\RedispatchMessage;

$recurring = RecurringMessage::every(
    '15 minutes',
    new RedispatchMessage(new RefreshPublicCatalog(), 'scheduled_async'),
);
```

Tenant work provides the tenant explicitly inside the encapsulated envelope:

```php
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

$recurring = RecurringMessage::every(
    '1 hour',
    new RedispatchMessage(
        new Envelope(new RebuildTenantIndex(), [new TenantStamp('42')]),
        'scheduled_async',
    ),
);
```

`scheduled_async` must be an existing persistent Symfony Messenger transport.
Run its application Worker separately from `scheduler_{schedule_name}` and
assert that no application handler executes during the Scheduler Worker pass.

## Security behavior

RC9 recognizes only Symfony's `RedispatchMessage`, validates its public
encapsulated object recursively with a maximum depth of eight, and preserves
the RC8 fail-closed classification and routing strategies. Unknown wrappers,
unreadable or cyclic structure, absent or invalid destinations, tenant/global
ambiguity, missing or contradictory tenant stamps, unknown tenants, and global
messages with tenant stamps are rejected before business handling.

The bundle does not add a public classification resolver or generic vendor
allowlist. Symfony continues to validate transport aliases and perform the
actual redispatch. See the complete [Scheduler documentation](scheduler.md).

## Rollback

Returning to RC8 reintroduces the `UnclassifiedMessageException` on valid
Scheduler `RedispatchMessage` instances. Pause the Scheduler consumers before a
rollback; do not weaken message classification as a compatibility workaround.
