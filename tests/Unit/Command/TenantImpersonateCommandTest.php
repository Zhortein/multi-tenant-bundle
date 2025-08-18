<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Command\TenantImpersonateCommand;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Command\TenantImpersonateCommand
 */
final class TenantImpersonateCommandTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private TenantContextInterface $tenantContext;
    private TenantImpersonateCommand $command;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->command = new TenantImpersonateCommand(
            $this->tenantRegistry,
            $this->tenantContext,
            true // Allow impersonation
        );
    }

    public function testExecuteWithValidTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');
        $tenant->method('getId')->willReturn('123');

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['tenant-identifier' => 'test-tenant']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Successfully impersonating tenant: test-tenant', $commandTester->getDisplay());
    }

    public function testExecuteWithInvalidTenant(): void
    {
        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('invalid-tenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('invalid-tenant')
            ->willReturn(null);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['tenant-identifier' => 'invalid-tenant']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Tenant not found: invalid-tenant', $commandTester->getDisplay());
    }

    public function testExecuteWithImpersonationDisabled(): void
    {
        $command = new TenantImpersonateCommand(
            $this->tenantRegistry,
            $this->tenantContext,
            false // Disable impersonation
        );

        $commandTester = new CommandTester($command);
        $commandTester->execute(['tenant-identifier' => 'test-tenant']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Tenant impersonation is disabled', $commandTester->getDisplay());
    }

    public function testExecuteWithShowConfig(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');
        $tenant->method('getId')->willReturn('123');
        $tenant->method('getMailerDsn')->willReturn('smtp://user:pass@localhost:587');
        $tenant->method('getMessengerDsn')->willReturn('redis://user:pass@localhost:6379');

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'tenant-identifier' => 'test-tenant',
            '--show-config' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tenant Configuration', $output);
        $this->assertStringContainsString('test-tenant', $output);
        $this->assertStringContainsString('123', $output);
        // Check that sensitive data is masked
        $this->assertStringContainsString('smtp://user:***@localhost:587', $output);
        $this->assertStringContainsString('redis://user:***@localhost:6379', $output);
    }

    public function testExecuteWithCommand(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');
        $tenant->method('getId')->willReturn('123');

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $this->tenantContext->expects($this->once())
            ->method('getTenant')
            ->willReturn($tenant);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'tenant-identifier' => 'test-tenant',
            '--command' => 'doctrine:schema:validate',
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Executing command: doctrine:schema:validate', $output);
        $this->assertStringContainsString('Would execute: doctrine:schema:validate', $output);
        $this->assertStringContainsString('In tenant context: test-tenant', $output);
    }

    public function testExecuteWithInteractiveMode(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');
        $tenant->method('getId')->willReturn('123');

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'tenant-identifier' => 'test-tenant',
            '--interactive' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Interactive Tenant Mode', $output);
        $this->assertStringContainsString('You are now in interactive mode for tenant: test-tenant', $output);
    }

    public function testMaskSensitiveData(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('maskSensitiveData');
        $method->setAccessible(true);

        // Test various DSN formats
        $testCases = [
            'smtp://user:password@localhost:587' => 'smtp://user:***@localhost:587',
            'redis://user:secret@redis.example.com:6379' => 'redis://user:***@redis.example.com:6379',
            'mysql://root:pass@localhost:3306/db' => 'mysql://root:***@localhost:3306/db',
            'postgresql://user:pwd@db.example.com:5432/mydb' => 'postgresql://user:***@db.example.com:5432/mydb',
            'simple-string' => 'simple-string', // No change for non-DSN strings
        ];

        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($this->command, $input);
            $this->assertSame($expected, $result, "Failed for input: $input");
        }
    }

    public function testExecuteWithNumericTenantIdentifier(): void
    {
        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('123')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with(123)
            ->willReturn(null);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['tenant-identifier' => '123']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Tenant not found: 123', $commandTester->getDisplay());
    }

    public function testSecurityWarningIsDisplayed(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('test-tenant');
        $tenant->method('getId')->willReturn('123');

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('test-tenant')
            ->willReturn($tenant);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['tenant-identifier' => 'test-tenant']);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('SECURITY WARNING', $output);
        $this->assertStringContainsString('You are about to impersonate a tenant', $output);
        $this->assertStringContainsString('Use this command responsibly', $output);
    }
}
