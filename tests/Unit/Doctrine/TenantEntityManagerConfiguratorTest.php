<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Doctrine\TenantEntityManagerConfigurator;
use Zhortein\MultiTenantBundle\Exception\DoctrineProtectionException;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class TenantEntityManagerConfiguratorTest extends TestCase
{
    public function testLateManagerInTenantContextIsConfiguredBeforeUse(): void
    {
        [$configurator, $filter] = $this->configurator(TenantConnectionState::tenant((new TestTenant())->setId(42)));

        $configurator->configure($this->manager);

        self::assertSame("'tenant'", $filter->getParameter('tenant_context_mode'));
        self::assertSame("'42'", $filter->getParameter('tenant_id'));
    }

    public function testLateManagerWithoutContextRemainsFailClosed(): void
    {
        [$configurator, $filter] = $this->configurator(TenantConnectionState::none());

        $configurator->configure($this->manager);

        self::assertSame("'none'", $filter->getParameter('tenant_context_mode'));
        self::assertSame("'__NO_TENANT__'", $filter->getParameter('tenant_id'));
    }

    public function testLateManagerInExplicitGlobalScopeIsSuspendedForThatScope(): void
    {
        [$configurator, $filter, $filters] = $this->configurator(TenantConnectionState::global());
        $filters->expects(self::once())->method('suspend')->with('tenant_filter');

        $configurator->configure($this->manager);

        self::assertSame("'global'", $filter->getParameter('tenant_context_mode'));
    }

    public function testDisabledFilterRejectsLateManager(): void
    {
        [$configurator, , $filters] = $this->configurator(TenantConnectionState::none(), false);
        $filters->expects(self::never())->method('getFilter');

        $this->expectException(DoctrineProtectionException::class);
        $configurator->configure($this->manager);
    }

    private EntityManagerInterface $manager;

    /** @return array{TenantEntityManagerConfigurator, TenantDoctrineFilter, FilterCollection} */
    private function configurator(TenantConnectionState $state, bool $enabled = true): array
    {
        $this->manager = $this->createMock(EntityManagerInterface::class);
        $this->manager->method('getConnection')->willReturn(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $filters = $this->getMockBuilder(FilterCollection::class)->disableOriginalConstructor()->getMock();
        $filter = new TenantDoctrineFilter($this->manager);
        $filters->method('isEnabled')->with('tenant_filter')->willReturn($enabled);
        $filters->method('getFilter')->with('tenant_filter')->willReturn($filter);
        $this->manager->method('getFilters')->willReturn($filters);
        $synchronizer = new class($state) implements TenantContextSynchronizerInterface {
            public function __construct(private readonly TenantConnectionState $state)
            {
            }

            public function currentState(): TenantConnectionState
            {
                return $this->state;
            }

            public function transition(TenantConnectionState $current, TenantConnectionState $target): void
            {
            }
        };
        $inner = new class {
            public function configure(EntityManagerInterface $entityManager): void
            {
            }
        };

        return [new TenantEntityManagerConfigurator($inner, $synchronizer), $filter, $filters];
    }
}
