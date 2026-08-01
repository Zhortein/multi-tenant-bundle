# Public Test Kit

The bundle ships a small optional test API through its normal production
autoload under `Zhortein\MultiTenantBundle\Test`. The public API is versioned
with the bundle. It never loads the internal `Tests\` namespace, bundle fixtures,
or demo entities.

## Installation

The scope helper itself needs only the bundle. The two PHPUnit base classes are
loaded only when a consumer extends them. Install the matching development
dependencies in the consuming application:

```shell
composer require --dev phpunit/phpunit:^12.2 "symfony/browser-kit:^7.4 || ^8.0" "symfony/dom-crawler:^7.4 || ^8.0"
```

`TenantKernelTestCase` requires FrameworkBundle, which is already part of a
normal Symfony application. `TenantWebTestCase` additionally requires
BrowserKit and DomCrawler. Missing Symfony testing components fail with the
standard actionable FrameworkBundle error.

Minimal kernels that do not install `symfony/runtime` should register the
Symfony error handler in their PHPUnit bootstrap before per-test handler state
is captured:

```php
<?php

use Symfony\Component\ErrorHandler\ErrorHandler;

require dirname(__DIR__)."/vendor/autoload.php";

ErrorHandler::register(null, false);
```

The external fixture at `tests/ConsumerApp` is the executable reference for
this setup on every supported consumer matrix cell.

## Canonical API

### TenantContextScope

`TenantContextScope` is the framework-independent primitive. It accepts the
public `TenantContextInterface` and any consumer tenant implementing
`TenantInterface`. `run()` restores the previous context in a `finally` block,
including nested calls and callbacks that throw.

```php
use App\Entity\Tenant;
use Zhortein\MultiTenantBundle\Test\TenantContextScope;

$scope = new TenantContextScope($tenantContext);
$result = $scope->run(
    new Tenant("tenant-a"),
    fn (): array => $repository->findForTenant("tenant-a"),
);
```

### TenantKernelTestCase

`TenantKernelTestCase` extends the standard Symfony `KernelTestCase` and adds
three protected methods:

- `withTenant(TenantInterface $tenant, callable $callback): mixed`;
- `tenantContext(): TenantContextInterface`;
- `clearTenantContext(): void`.

The class clears an active context before shutting down the kernel. A subclass
that overrides `tearDown()` must always call `parent::tearDown()`.

```php
use App\Entity\Tenant;
use Zhortein\MultiTenantBundle\Test\TenantKernelTestCase;

final class ProductRepositoryTest extends TenantKernelTestCase
{
    public function testIsolation(): void
    {
        $tenantA = new Tenant("tenant-a");
        $tenantB = new Tenant("tenant-b");

        $productsA = $this->withTenant(
            $tenantA,
            fn (): array => $this->repository->findForTenant($tenantA),
        );
        $productsB = $this->withTenant(
            $tenantB,
            fn (): array => $this->repository->findForTenant($tenantB),
        );

        self::assertSame(["A product"], $productsA);
        self::assertSame(["B product"], $productsB);
        self::assertNull($this->tenantContext()->getTenant());
    }
}
```

### TenantWebTestCase

`TenantWebTestCase` provides the same protected tenant lifecycle on top of
Symfony `WebTestCase`. It does not choose a resolver, host, header, URL layout,
or database strategy. When a test supplies context directly for an HTTP
request, disable client reboot for that scoped request; otherwise the new kernel
correctly starts with an empty context.

```php
$client = static::createClient();
$client->disableReboot();

$this->withTenant($tenantA, static function () use ($client): void {
    $client->request("GET", "/products");
});

self::assertResponseIsSuccessful();
```

Resolver behavior should instead be tested by sending the application-specific
host, header, query, or path input to a normally rebooting client.

## Isolation contract

The Test Kit manages context; it does not claim that context alone enforces data
isolation. A consumer test must still assert both permitted same-tenant behavior
and rejected cross-tenant behavior through explicit repository criteria,
authorization checks, Doctrine filtering, or PostgreSQL RLS as appropriate.
A useful sequence is A/B/A so stale state and accidental caching are observable.

The package intentionally does not publish tenant stubs, fixture builders,
resolver-specific clients, CLI helpers, Messenger helpers, or automatic
Doctrine-filter controls. Consumers own their entities and data setup, while
Symfony and PHPUnit already provide the non-tenant-specific testing APIs.

## Internal bundle suite

Classes under `Zhortein\MultiTenantBundle\Tests` belong only to this repository
and are loaded through `autoload-dev`. They are not public Test Kit APIs and must
never appear in consumer code.
