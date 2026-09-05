# Migrating from RC9 to RC10

RC10's composition fix preserves application message interfaces, positional
middleware constructors, routing settings, Scheduler schedules and consumer
middleware configuration. It adds no public classification API.

## Why the change is necessary

RC9 used `prependExtensionConfig()` to add its guards. Symfony's middleware
configuration uses `performNoDeepMerging()`, so an application list such as
`middleware: [validation]` replaced those guards. The compiled bus still
validated payloads but could persist unclassified messages and handle a
received tenant message without context.

The internal compiler pass now composes the public bus constructor iterable
after `MessengerPass`. Existing application middleware remain present and in
their relative order; explicitly configured bundle middleware remain present
once. Every tagged Symfony bus is protected automatically.

## Consumer configuration

Keep existing validation, application middleware, routes, transport names and
`RedispatchMessage` schedules. Rebuild the application container when deploying
the updated package, as for any bundle compiler-pass change. Do not add the
bundle middleware manually to compensate for RC9's configuration replacement.
Existing explicit references remain supported.

Envelope preparation precedes classification. The tenant context encloses
validation, handlers and the complete deferred queue. Symfony continues to
perform routing, sending, handling and failure replay. See the detailed
[Messenger contract](messenger.md) and [Scheduler proof](scheduler.md).

## Dependency compatibility

`symfony/messenger` remains a required runtime dependency, as in RC9. Consumers
relying on that transitive dependency need no Composer manifest change. The
public `TenantSessionConfigurator` keeps its Messenger interface and constructor.

To disable automatic Messenger integration, set
`zhortein_multi_tenant.messenger.enabled: false`. The component stays installed,
but the bundle does not register its Messenger services or alter bus chains.
The minimal production installation tests this configuration with optional
Mailer, Twig, Monolog, PSR-16 and Scheduler components absent. The rejected
optional-Messenger prototype and the compatibility decision are recorded in
the [audit](audit-rc9-messenger-composition.md).

## Rollback

Returning to RC9 reintroduces the middleware-replacement defect. A green
Symfony validation test alone does not prove tenant protection. Preserve the
compiled-chain and received-context regression checks when assessing a
rollback; never disable classification to make a failing message pass.
