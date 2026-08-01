<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Depends;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Test\TenantContextScope;
use Zhortein\MultiTenantBundle\Test\TenantKernelTestCase;
use Zhortein\MultiTenantBundle\Test\TenantWebTestCase;

final class PublicTestKitTest extends TenantKernelTestCase
{
    private static ?TenantContextInterface $contextLeftForTearDown = null;

    public function testPublicClassesComeFromProductionAutoloadOnly(): void
    {
        self::assertTrue(class_exists(TenantContextScope::class));
        self::assertTrue(class_exists(TenantKernelTestCase::class));
        self::assertTrue(class_exists(TenantWebTestCase::class));
        self::assertFalse(class_exists('Zhortein\\MultiTenantBundle\\Tests\\Toolkit\\TenantWebTestCase'));
    }

    public function testConsumerTenantsRemainIsolatedAcrossSequentialScopes(): void
    {
        $tenantA = new Tenant('tenant-a', 'tenant-a');
        $tenantB = new Tenant('tenant-b', 'tenant-b');

        $observed = [
            $this->withTenant($tenantA, fn (): string|int => $this->tenantContext()->getTenant()?->getId() ?? 'missing'),
            $this->withTenant($tenantB, fn (): string|int => $this->tenantContext()->getTenant()?->getId() ?? 'missing'),
            $this->withTenant($tenantA, fn (): string|int => $this->tenantContext()->getTenant()?->getId() ?? 'missing'),
        ];

        self::assertSame(['tenant-a', 'tenant-b', 'tenant-a'], $observed);
        self::assertNull($this->tenantContext()->getTenant());
    }

    public function testPreviousContextIsRestoredAfterAnException(): void
    {
        $tenantA = new Tenant('tenant-a', 'tenant-a');
        $tenantB = new Tenant('tenant-b', 'tenant-b');
        $this->tenantContext()->setTenant($tenantA);

        try {
            $this->withTenant($tenantB, static function (): never {
                throw new \RuntimeException('Expected consumer failure.');
            });
            self::fail('The callback exception must be propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Expected consumer failure.', $exception->getMessage());
        }

        self::assertSame($tenantA, $this->tenantContext()->getTenant());
    }

    public function testARebootStartsWithAnEmptyTenantContext(): void
    {
        $this->tenantContext()->setTenant(new Tenant('tenant-a', 'tenant-a'));

        static::ensureKernelShutdown();
        static::bootKernel();

        self::assertNull($this->tenantContext()->getTenant());
    }

    public function testBaseClassTearDownClearsAnUnscopedContext(): void
    {
        self::$contextLeftForTearDown = $this->tenantContext();
        self::$contextLeftForTearDown->setTenant(new Tenant('tenant-a', 'tenant-a'));

        self::assertNotNull(self::$contextLeftForTearDown->getTenant());
    }

    #[Depends('testBaseClassTearDownClearsAnUnscopedContext')]
    public function testPreviousContextObjectWasClearedBeforeKernelShutdown(): void
    {
        self::assertInstanceOf(TenantContextInterface::class, self::$contextLeftForTearDown);
        self::assertNull(self::$contextLeftForTearDown->getTenant());
    }
}
