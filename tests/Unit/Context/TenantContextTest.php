<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantStateResetException;

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

    public function testContextIsAResettableServiceAndResetClearsTenantState(): void
    {
        $tenant = $this->createStub(TenantInterface::class);
        $this->tenantContext->setTenant($tenant);

        self::assertInstanceOf(ResetInterface::class, $this->tenantContext);
        $this->tenantContext->reset();

        self::assertNull($this->tenantContext->getTenant());
    }

    public function testSetTenant(): void
    {
        $tenant = $this->createStub(TenantInterface::class);

        $this->tenantContext->setTenant($tenant);

        $this->assertSame($tenant, $this->tenantContext->getTenant());
        $this->assertTrue($this->tenantContext->hasTenant());
    }

    public function testClearTenant(): void
    {
        $tenant = $this->createStub(TenantInterface::class);

        $this->tenantContext->setTenant($tenant);
        $this->assertTrue($this->tenantContext->hasTenant());

        $this->tenantContext->clear();

        $this->assertNull($this->tenantContext->getTenant());
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testSetTenantOverwritesPrevious(): void
    {
        $tenant1 = $this->createStub(TenantInterface::class);
        $tenant2 = $this->createStub(TenantInterface::class);

        $this->tenantContext->setTenant($tenant1);
        $this->assertSame($tenant1, $this->tenantContext->getTenant());

        $this->tenantContext->setTenant($tenant2);
        $this->assertSame($tenant2, $this->tenantContext->getTenant());
        $this->assertNotSame($tenant1, $this->tenantContext->getTenant());
    }

    public function testFailedSynchronizationLeavesPreviousContextUntouched(): void
    {
        $tenantA = $this->createStub(TenantInterface::class);
        $tenantB = $this->createStub(TenantInterface::class);
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

            public function reset(): void
            {
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

    public function testFailedResetStillInvalidatesLogicalTenantFirst(): void
    {
        $tenant = $this->createStub(TenantInterface::class);
        $synchronizer = new class implements TenantContextSynchronizerInterface {
            public function currentState(): TenantConnectionState
            {
                return TenantConnectionState::none();
            }

            public function transition(TenantConnectionState $current, TenantConnectionState $target): void
            {
            }

            public function reset(): void
            {
                throw new \RuntimeException('reset failed');
            }
        };
        $context = new TenantContext(null, $synchronizer);
        $context->setTenant($tenant);

        try {
            $context->reset();
            self::fail('The reset failure must remain observable.');
        } catch (TenantStateResetException $exception) {
            self::assertSame([\RuntimeException::class], $exception->getFailureTypes());
            self::assertStringNotContainsString('reset failed', $exception->getMessage());
        }

        self::assertNull($context->getTenant());
    }
}
