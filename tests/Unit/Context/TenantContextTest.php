<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Context\TenantContext
 */
final class TenantContextTest extends TestCase
{
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
    }

    public function testInitialStateHasNoTenant(): void
    {
        $this->assertNull($this->tenantContext->getTenant());
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testSetTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantContext->setTenant($tenant);

        $this->assertSame($tenant, $this->tenantContext->getTenant());
        $this->assertTrue($this->tenantContext->hasTenant());
    }

    public function testClearTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantContext->setTenant($tenant);
        $this->assertTrue($this->tenantContext->hasTenant());

        $this->tenantContext->clear();

        $this->assertNull($this->tenantContext->getTenant());
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testSetTenantOverwritesPrevious(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant2 = $this->createMock(TenantInterface::class);

        $this->tenantContext->setTenant($tenant1);
        $this->assertSame($tenant1, $this->tenantContext->getTenant());

        $this->tenantContext->setTenant($tenant2);
        $this->assertSame($tenant2, $this->tenantContext->getTenant());
        $this->assertNotSame($tenant1, $this->tenantContext->getTenant());
    }

    public function testFailedSynchronizationLeavesPreviousContextUntouched(): void
    {
        $tenantA = $this->createMock(TenantInterface::class);
        $tenantB = $this->createMock(TenantInterface::class);
        $synchronizer = new class implements TenantContextSynchronizerInterface {
            public int $calls = 0;

            public function currentState(): TenantConnectionState
            {
                return TenantConnectionState::none();
            }

            public function transition(TenantConnectionState $current, TenantConnectionState $target): void
            {
                if (2 === ++$this->calls) {
                    throw new \RuntimeException('transition failed');
                }
            }
        };
        $context = new TenantContext(null, $synchronizer);
        $context->setTenant($tenantA);

        try {
            $context->setTenant($tenantB);
            self::fail('The transition should fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transition failed', $exception->getMessage());
        }

        self::assertSame($tenantA, $context->getTenant());
    }
}
