# Public Test Kit Examples

The canonical public API is documented in [Testing](../testing.md). Executable
consumer examples live in `tests/ConsumerApp/tests` and run after Composer has
installed the bundle as a non-symlinked external dependency.

## Kernel lifecycle example

`PublicTestKitTest.php` demonstrates:

- a consumer-defined `Tenant` implementing the public tenant interface;
- A/B/A sequential scopes;
- restoration after a callback exception;
- restoration of a previous non-empty context;
- an empty context after a kernel reboot;
- absence of the internal `Tests\Toolkit` namespace from consumer autoload.

Run the same pattern against an application repository whose tenant-facing
methods require an explicit tenant criterion.

## Functional lifecycle example

`PublicTenantWebTest.php` creates a normal Symfony `KernelBrowser`, disables
reboot only while supplying context directly, and performs A/B/A requests. It
then reboots the kernel, proves that no tenant leaked, and performs another
scoped request.

A resolver-specific application should leave reboot enabled and provide its
normal request input instead. The public Test Kit deliberately does not invent
resolver-specific client methods.

## Compatibility execution

The Compatibility workflow copies `tests/ConsumerApp` outside this repository,
installs the bundle through normal production autoload with Composer path
symlinks disabled, and runs both the container validation and PHPUnit suite for
`shared_db` and `multi_db`. Matrix cells include Symfony 7.4 and Symfony 8.1 on
PHP 8.5.

PostgreSQL RLS requires the separate real PostgreSQL suite:

```shell
make test-with-postgres
```

An in-memory context test is not a substitute for an RLS assertion executed as
a non-superuser database role.
