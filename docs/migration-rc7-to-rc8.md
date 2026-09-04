# Migrating from RC7 to RC8

RC8 adds an explicit Messenger routing strategy. The default remains `tenant_transport`, so existing RC7 consumers keep the same tenant map and default transport behavior without a configuration change.

## Previous behavior

RC7 added a `TransportNamesStamp` to every tenant-aware dispatch with an active tenant unless the consumer supplied one. Symfony gives that stamp priority over `framework.messenger.routing` and `#[AsMessage]`, so logical message routes could not select another transport.

## Native Symfony routing

Applications that route by message class or `#[AsMessage]` should opt in:

```yaml
zhortein_multi_tenant:
    messenger:
        routing_strategy: symfony_routing
```

In this mode the bundle does not add, replace, or remove any `TransportNamesStamp`. `tenant_transport_map` and `default_transport` have no effect on envelopes, and there is no bundle fallback. Symfony retains responsibility for configured routes, attributes, explicit stamps, alias validation, and synchronous handling.

Audit routes before enabling native mode. If a message has a handler but no matching sender, Symfony can handle it synchronously. Define exhaustive `framework.messenger.routing` entries wherever asynchronous delivery is required.

## Compatibility and rollback

The enum and constructor argument are additive; the new argument is last and defaults to `MessengerRoutingStrategy::TENANT_TRANSPORT`, preserving positional construction. Both strategies keep RC7's exact message classification, tenant-context requirement, `TenantStamp` consistency, receive-side tenant validation, handler rejection, all-bus middleware installation, and cleanup after success or failure.

To roll back routing behavior, set `routing_strategy: tenant_transport` and restore any required `tenant_transport_map` and `default_transport` values before deploying the older package. No data migration is required.
