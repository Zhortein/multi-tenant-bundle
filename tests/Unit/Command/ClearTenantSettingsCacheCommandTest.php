<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Command\ClearTenantSettingsCacheCommand;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Command\ClearTenantSettingsCacheCommand
 */
final class ClearTenantSettingsCacheCommandTest extends TestCase
{
    private ClearTenantSettingsCacheCommand $command;
    private CacheItemPoolInterface $cache;
    private TenantRegistryInterface $tenantRegistry;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);

        $this->command = new ClearTenantSettingsCacheCommand(
            $this->cache,
            $this->tenantRegistry
        );
    }

    public function testExecuteWithoutArgumentsOrOptionsShowsError(): void
    {
        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Please specify either a tenant slug or use --all option.', $commandTester->getDisplay());
    }

    public function testExecuteWithSpecificTenantSlug(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantRegistry->method('getBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $this->cache->expects($this->once())
            ->method('deleteItem')
            ->with('zhortein_tenant_settings_tenant-1')
            ->willReturn(true);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute(['tenant-slug' => 'test-tenant']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Cache cleared for tenant `test-tenant`.', $commandTester->getDisplay());
    }

    public function testExecuteWithNonExistentTenant(): void
    {
        $this->tenantRegistry->method('getBySlug')
            ->with('non-existent')
            ->willThrowException(new \RuntimeException("Tenant with slug 'non-existent' not found."));

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute(['tenant-slug' => 'non-existent']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Tenant `non-existent` not found.', $commandTester->getDisplay());
    }

    public function testExecuteWithAllOption(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant1->method('getId')->willReturn('tenant-1');

        $tenant2 = $this->createMock(TenantInterface::class);
        $tenant2->method('getId')->willReturn('tenant-2');

        $this->tenantRegistry->method('getAll')->willReturn([$tenant1, $tenant2]);

        $this->cache->expects($this->exactly(2))
            ->method('deleteItem')
            ->with($this->logicalOr(
                'zhortein_tenant_settings_tenant-1',
                'zhortein_tenant_settings_tenant-2'
            ))
            ->willReturn(true);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute(['--all' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Cache cleared for 2 tenants.', $commandTester->getDisplay());
    }

    public function testExecuteWithAllOptionWhenSomeCacheItemsNotFound(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant1->method('getId')->willReturn('tenant-1');

        $tenant2 = $this->createMock(TenantInterface::class);
        $tenant2->method('getId')->willReturn('tenant-2');

        $this->tenantRegistry->method('getAll')->willReturn([$tenant1, $tenant2]);

        $this->cache->expects($this->exactly(2))
            ->method('deleteItem')
            ->willReturnCallback(function ($key) {
                return 'zhortein_tenant_settings_tenant-1' === $key;
            });

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute(['--all' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Cache cleared for 1 tenant.', $commandTester->getDisplay());
    }

    public function testExecuteWithSpecificTenantWhenCacheNotFound(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $this->tenantRegistry->method('getBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $this->cache->expects($this->once())
            ->method('deleteItem')
            ->with('zhortein_tenant_settings_tenant-1')
            ->willReturn(false);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute(['tenant-slug' => 'test-tenant']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No cache found for tenant `test-tenant`.', $commandTester->getDisplay());
    }

    public function testCommandConfiguration(): void
    {
        $this->assertSame('tenant:settings:clear-cache', $this->command->getName());
        $this->assertSame('Clears the tenant settings cache for all or specific tenants', $this->command->getDescription());
    }
}
