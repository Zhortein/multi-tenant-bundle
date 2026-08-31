<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScopeInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class LateEntityManagerIntegrationTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testResetManagerAfterTenantSelectionIsProtectedBeforeDelivery(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $registry = $container->get(ManagerRegistry::class);
        $context = $container->get(TenantContextInterface::class);
        $original = $registry->getManager();
        $context->setTenant((new TestTenant())->setId(41)->setSlug('tenant-a'));
        $original->close();
        self::assertFalse($original->isOpen());
        $registry->resetManager();
        $reset = $registry->getManager();

        self::assertTrue($reset->isOpen());
        self::assertTrue($reset->getFilters()->isEnabled('tenant_filter'));
        self::assertSame("'tenant'", $reset->getFilters()->getFilter('tenant_filter')->getParameter('tenant_context_mode'));
        self::assertSame("'41'", $reset->getFilters()->getFilter('tenant_filter')->getParameter('tenant_id'));
    }

    public function testResetManagerWithoutTenantRemainsFailClosed(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(ManagerRegistry::class);

        $registry->getManager()->close();
        $registry->resetManager();
        $reset = $registry->getManager();

        self::assertTrue($reset->getFilters()->isEnabled('tenant_filter'));
        self::assertSame("'none'", $reset->getFilters()->getFilter('tenant_filter')->getParameter('tenant_context_mode'));
        self::assertSame("'__NO_TENANT__'", $reset->getFilters()->getFilter('tenant_filter')->getParameter('tenant_id'));
    }

    public function testManagerCreatedInsideGlobalScopeIsRestoredOnExit(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $registry = $container->get(ManagerRegistry::class);
        $scope = $container->get(GlobalDoctrineScopeInterface::class);

        $scope->run(function () use ($registry): void {
            $registry->getManager()->close();
            $registry->resetManager();
            $manager = $registry->getManager();
            self::assertTrue($manager->getFilters()->isSuspended('tenant_filter'));
        });

        $manager = $registry->getManager();
        self::assertTrue($manager->getFilters()->isEnabled('tenant_filter'));
        self::assertSame("'none'", $manager->getFilters()->getFilter('tenant_filter')->getParameter('tenant_context_mode'));
    }
}
