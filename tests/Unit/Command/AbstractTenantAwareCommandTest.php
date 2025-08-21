<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Command\AbstractTenantAwareCommand;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Command\AbstractTenantAwareCommand
 */
final class AbstractTenantAwareCommandTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private TenantContextInterface $tenantContext;
    private AbstractTenantAwareCommand $command;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);

        $this->command = new class($this->tenantRegistry, $this->tenantContext) extends AbstractTenantAwareCommand {
            protected function doExecute(InputInterface $input, OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };
    }

    public function testResolveTenantIdentifierFromOption(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->expects($this->once())
            ->method('getOption')
            ->with('tenant')
            ->willReturn('test-tenant');

        $result = $this->command->resolveTenantIdentifier($input);

        $this->assertSame('test-tenant', $result);
    }

    public function testResolveTenantIdentifierFromEnvironment(): void
    {
        $_ENV['TENANT_ID'] = 'env-tenant';

        $input = $this->createMock(InputInterface::class);
        $input->expects($this->once())
            ->method('getOption')
            ->with('tenant')
            ->willReturn(null);

        $result = $this->command->resolveTenantIdentifier($input);

        $this->assertSame('env-tenant', $result);

        unset($_ENV['TENANT_ID']);
    }

    public function testResolveTenantIdentifierFromServer(): void
    {
        $_SERVER['TENANT_ID'] = 'server-tenant';

        $input = $this->createMock(InputInterface::class);
        $input->expects($this->once())
            ->method('getOption')
            ->with('tenant')
            ->willReturn(null);

        $result = $this->command->resolveTenantIdentifier($input);

        $this->assertSame('server-tenant', $result);

        unset($_SERVER['TENANT_ID']);
    }

    public function testResolveTenantIdentifierReturnsNullWhenNotFound(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->expects($this->once())
            ->method('getOption')
            ->with('tenant')
            ->willReturn(null);

        $result = $this->command->resolveTenantIdentifier($input);

        $this->assertNull($result);
    }

    public function testResolveTenantBySlug(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('test-slug')
            ->willReturn($tenant);

        $result = $this->command->resolveTenant('test-slug');

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantByNumericId(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('123')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with(123)
            ->willReturn($tenant);

        $result = $this->command->resolveTenant('123');

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantByStringId(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('abc123')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('abc123')
            ->willReturn($tenant);

        $result = $this->command->resolveTenant('abc123');

        $this->assertSame($tenant, $result);
    }

    public function testResolveTenantReturnsNullWhenNotFound(): void
    {
        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('nonexistent')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->command->resolveTenant('nonexistent');

        $this->assertNull($result);
    }

    public function testGetCurrentTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantContext->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $result = $this->command->getCurrentTenant();

        $this->assertSame($tenant, $result);
    }

    public function testGetTargetTenantsWithCurrentTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->tenantContext->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $result = $this->command->getTargetTenants();

        $this->assertSame([$tenant], $result);
    }

    public function testGetTargetTenantsWithoutCurrentTenant(): void
    {
        $tenants = [
            $this->createMock(TenantInterface::class),
            $this->createMock(TenantInterface::class),
        ];

        $this->tenantContext->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn($tenants);

        $result = $this->command->getTargetTenants();

        $this->assertSame($tenants, $result);
    }

    public function testValidateTenantRequirementWithTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $io = $this->createMock(SymfonyStyle::class);

        $command = new class($this->tenantRegistry, $this->tenantContext) extends AbstractTenantAwareCommand {
            protected function doExecute(InputInterface $input, OutputInterface $output): int
            {
                return self::SUCCESS;
            }

            protected function requiresTenant(): bool
            {
                return true;
            }
        };

        $this->tenantContext->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $result = $command->validateTenantRequirement($io);

        $this->assertTrue($result);
    }

    public function testValidateTenantRequirementWithoutTenant(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('error')
            ->with('This command requires a tenant context. Use --tenant option or set TENANT_ID environment variable.');

        $command = new class($this->tenantRegistry, $this->tenantContext) extends AbstractTenantAwareCommand {
            protected function doExecute(InputInterface $input, OutputInterface $output): int
            {
                return self::SUCCESS;
            }

            protected function requiresTenant(): bool
            {
                return true;
            }
        };

        $this->tenantContext->expects($this->once())
            ->method('getTenant')
            ->willReturn(null);

        $result = $command->validateTenantRequirement($io);

        $this->assertFalse($result);
    }

    public function testCommandExecutionWithTenantContext(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $commandTester = new \Symfony\Component\Console\Tester\CommandTester($this->command);
        $commandTester->execute(['--tenant' => 'test-tenant']);

        $this->assertSame(\Symfony\Component\Console\Command\Command::SUCCESS, $commandTester->getStatusCode());
    }

    public function testCommandExecutionWithInvalidTenant(): void
    {
        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('invalid-tenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('invalid-tenant')
            ->willReturn(null);

        $commandTester = new \Symfony\Component\Console\Tester\CommandTester($this->command);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tenant: invalid-tenant');

        $commandTester->execute(['--tenant' => 'invalid-tenant']);
    }
}
