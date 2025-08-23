<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Command\ListTenantsCommand;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Command\ListTenantsCommand
 */
final class ListTenantsCommandTest extends TestCase
{
    private TenantRegistryInterface $tenantRegistry;
    private TenantContextInterface $tenantContext;
    private ListTenantsCommand $command;

    protected function setUp(): void
    {
        $this->tenantRegistry = $this->createMock(TenantRegistryInterface::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);

        $this->command = new ListTenantsCommand(
            $this->tenantRegistry,
            $this->tenantContext
        );
    }

    public function testExecuteWithNoTenants(): void
    {
        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn([]);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('No tenants found', $commandTester->getDisplay());
    }

    public function testExecuteWithTenants(): void
    {
        $tenant1 = $this->createMockTenant('1', 'tenant1', 'Tenant One');
        $tenant2 = $this->createMockTenant('2', 'tenant2', 'Tenant Two');

        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn([$tenant1, $tenant2]);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('tenant1', $output);
        $this->assertStringContainsString('tenant2', $output);
        $this->assertStringContainsString('Found 2 tenant(s)', $output);
    }

    public function testExecuteWithSpecificTenant(): void
    {
        $tenant = $this->createMockTenant('1', 'tenant1', 'Tenant One');

        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('tenant1')
            ->willReturn($tenant);

        $this->tenantContext->expects($this->once())
            ->method('setTenant')
            ->with($tenant);

        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn($tenant);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--tenant' => 'tenant1']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Operating on tenant: tenant1', $output);
        $this->assertStringContainsString('Found 1 tenant(s)', $output);
    }

    public function testExecuteWithDetailedOutput(): void
    {
        $tenant = $this->createMockTenant('1', 'tenant1', 'Tenant One', 'smtp://user:pass@localhost', 'redis://localhost');

        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn([$tenant]);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--detailed' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('tenant1', $output);
        // Check that sensitive data is masked
        $this->assertStringContainsString('smtp://user:***@localhost', $output);
        $this->assertStringContainsString('redis://localhost', $output);
    }

    public function testExecuteWithJsonFormat(): void
    {
        $tenant = $this->createMockTenant('1', 'tenant1', 'Tenant One');

        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn([$tenant]);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('1', $data[0]['id']);
        $this->assertSame('tenant1', $data[0]['slug']);
    }

    public function testExecuteWithJsonFormatDetailed(): void
    {
        $tenant = $this->createMockTenant('1', 'tenant1', 'Tenant One', 'smtp://localhost', 'redis://localhost');

        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn([$tenant]);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--format' => 'json', '--detailed' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('1', $data[0]['id']);
        $this->assertSame('tenant1', $data[0]['slug']);
        $this->assertNull($data[0]['name']); // getName method doesn't exist on mock
        $this->assertSame('smtp://localhost', $data[0]['mailer_dsn']);
        $this->assertSame('redis://localhost', $data[0]['messenger_dsn']);
    }

    public function testExecuteWithYamlFormat(): void
    {
        $tenant = $this->createMockTenant('1', 'tenant1', 'Tenant One');

        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn([$tenant]);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--format' => 'yaml']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('tenants:', $output);
        $this->assertStringContainsString('id: 1', $output);
        $this->assertStringContainsString('slug: tenant1', $output);
    }

    public function testExecuteWithYamlFormatDetailed(): void
    {
        $tenant = $this->createMockTenant('1', 'tenant1', 'Tenant One', 'smtp://localhost', 'redis://localhost');

        $this->tenantContext->expects($this->any())
            ->method('getTenant')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('getAll')
            ->willReturn([$tenant]);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['--format' => 'yaml', '--detailed' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('tenants:', $output);
        $this->assertStringContainsString('id: 1', $output);
        $this->assertStringContainsString('slug: tenant1', $output);
        $this->assertStringContainsString('name: null', $output); // getName method doesn't exist on mock
        $this->assertStringContainsString('mailer_dsn: smtp://localhost', $output);
        $this->assertStringContainsString('messenger_dsn: redis://localhost', $output);
    }

    public function testMaskSensitiveData(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('maskSensitiveData');
        $method->setAccessible(true);

        $testCases = [
            'N/A' => 'N/A',
            'smtp://user:password@localhost:587' => 'smtp://user:***@localhost:587',
            'redis://user:secret@redis.example.com:6379' => 'redis://user:***@redis.example.com:6379',
            'simple-string' => 'simple-string',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($this->command, $input);
            $this->assertSame($expected, $result, "Failed for input: $input");
        }
    }

    public function testExecuteWithUnknownTenant(): void
    {
        $this->tenantRegistry->expects($this->once())
            ->method('findBySlug')
            ->with('unknown')
            ->willReturn(null);

        $this->tenantRegistry->expects($this->once())
            ->method('findById')
            ->with('unknown')
            ->willReturn(null);

        $commandTester = new CommandTester($this->command);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tenant: unknown');

        $commandTester->execute(['--tenant' => 'unknown']);
    }

    private function createMockTenant(
        string $id,
        string $slug,
        ?string $name = null,
        ?string $mailerDsn = null,
        ?string $messengerDsn = null,
    ): TenantInterface {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getMailerDsn')->willReturn($mailerDsn);
        $tenant->method('getMessengerDsn')->willReturn($messengerDsn);

        // Don't try to mock getName since it's not part of the interface
        // The command will use method_exists() to check for it

        return $tenant;
    }
}
