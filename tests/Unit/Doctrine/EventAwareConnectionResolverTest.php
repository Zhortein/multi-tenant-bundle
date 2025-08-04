<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\EventAwareConnectionResolver;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionResolverInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Event\TenantDatabaseSwitchEvent;

/**
 * @covers \Zhortein\MultiTenantBundle\Doctrine\EventAwareConnectionResolver
 */
final class EventAwareConnectionResolverTest extends TestCase
{
    private TenantConnectionResolverInterface $innerResolver;
    private EventDispatcherInterface $eventDispatcher;
    private TenantContextInterface $tenantContext;
    private EventAwareConnectionResolver $resolver;

    protected function setUp(): void
    {
        $this->innerResolver = $this->createMock(TenantConnectionResolverInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);

        $this->resolver = new EventAwareConnectionResolver(
            $this->innerResolver,
            $this->eventDispatcher,
            $this->tenantContext
        );
    }

    public function testResolveParametersDelegatesToInnerResolver(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $expectedParams = ['host' => 'localhost', 'dbname' => 'tenant_db'];

        $this->innerResolver
            ->expects($this->once())
            ->method('resolveParameters')
            ->with($tenant)
            ->willReturn($expectedParams);

        $result = $this->resolver->resolveParameters($tenant);

        $this->assertSame($expectedParams, $result);
    }

    public function testSwitchToTenantConnectionDispatchesEvents(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $previousTenant = $this->createMock(TenantInterface::class);
        $connectionParams = ['host' => 'localhost', 'dbname' => 'tenant_db'];

        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn($previousTenant);

        $this->innerResolver
            ->expects($this->once())
            ->method('resolveParameters')
            ->with($tenant)
            ->willReturn($connectionParams);

        $this->innerResolver
            ->expects($this->once())
            ->method('switchToTenantConnection')
            ->with($tenant);

        // Expect before and after switch events
        $this->eventDispatcher
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->with(
                $this->callback(function (TenantDatabaseSwitchEvent $event) use ($tenant, $connectionParams, $previousTenant) {
                    return $event->getTenant() === $tenant
                        && $event->getConnectionParameters() === $connectionParams
                        && $event->getPreviousTenant() === $previousTenant;
                }),
                $this->logicalOr(
                    TenantDatabaseSwitchEvent::BEFORE_SWITCH,
                    TenantDatabaseSwitchEvent::AFTER_SWITCH
                )
            );

        $this->resolver->switchToTenantConnection($tenant);
    }

    public function testSwitchToTenantConnectionWithoutPreviousTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $connectionParams = ['host' => 'localhost', 'dbname' => 'tenant_db'];

        $this->tenantContext
            ->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $this->innerResolver
            ->expects($this->once())
            ->method('resolveParameters')
            ->with($tenant)
            ->willReturn($connectionParams);

        $this->innerResolver
            ->expects($this->once())
            ->method('switchToTenantConnection')
            ->with($tenant);

        // Expect events with null previous tenant
        $this->eventDispatcher
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->with(
                $this->callback(function (TenantDatabaseSwitchEvent $event) use ($tenant, $connectionParams) {
                    return $event->getTenant() === $tenant
                        && $event->getConnectionParameters() === $connectionParams
                        && null === $event->getPreviousTenant();
                }),
                $this->logicalOr(
                    TenantDatabaseSwitchEvent::BEFORE_SWITCH,
                    TenantDatabaseSwitchEvent::AFTER_SWITCH
                )
            );

        $this->resolver->switchToTenantConnection($tenant);
    }
}
